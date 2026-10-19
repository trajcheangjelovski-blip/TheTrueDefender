<?php

namespace App\Services;

use App\Models\IngestedItem;
use App\Models\IngestSource;
use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IngestService
{
    public function __construct(
        private FeedReader $reader,
        private Rewriter $rewriter,
        private ImageService $images,
        private Deduplicator $dedup,
        private SeoOptimizer $seo,
        private ArticleFetcher $articles,
        private SeoBooster $booster,
        private WebResearcher $research,
    ) {}

    /** Run every active source. Returns total number of new posts created. */
    public function runAll(): int
    {
        $created = 0;
        foreach (IngestSource::where('is_active', true)->get() as $source) {
            $created += $this->runSource($source);
        }
        return $created;
    }

    public function runSource(IngestSource $source): int
    {
        $created = 0;

        try {
            $items = $this->reader->read($source->feed_url, $source->max_items);
        } catch (\Throwable $e) {
            Log::warning("Ingest fetch failed for {$source->name}: " . $e->getMessage());
            return 0;
        }

        // Categories the AI may sort articles into (excludes Opinion — that's
        // the reader-discussion forum, not for auto-ingested news).
        $categories = \App\Models\Category::where('is_active', true)
            ->whereNotIn('slug', ['opinion'])
            ->get(['id', 'slug', 'name', 'icon']);
        $catList = $categories->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->all();

        // Only import fresh news — skip anything the source published longer ago
        // than this window (default 3h). An item with no feed date gets one more
        // chance from the article page's own published date (checked after fetch);
        // with no date anywhere it is skipped.
        $maxAgeHours = (float) \App\Models\Setting::get('ingest_max_age_hours', 3);
        $freshCutoff = now()->subHours($maxAgeHours);

        foreach ($items as $item) {
            // Freshness gate: drop stale stories so the site stays current.
            if (($item['published_at'] ?? null) && $item['published_at']->lt($freshCutoff)) {
                continue;
            }

            // Dedupe: skip any article we've already ingested (from ANY feed),
            // matched on the article URL with tracking/query params stripped.
            // Feeds like BBC rotate params (?at_campaign=…) on every fetch, so the
            // raw guid looks "new" each poll — keying on that re-ingested the same
            // story endlessly, which the cross-feed check then flagged as duplicate.
            $urlKey = $this->dedupKey($item['link'] ?? $item['guid'] ?? '');

            // Sources are ingested in parallel (one queued job per feed), so the
            // "seen it?" checks and the claiming record must happen under one lock —
            // otherwise two feeds carrying the same story could both pass dedupe.
            // Only this cheap step is serialized; the rewrite runs concurrently.
            $claim = \Illuminate\Support\Facades\Cache::lock('ingest:dedupe', 60);
            try {
                $claim->block(60);

                if ($urlKey !== '' && IngestedItem::where('guid', $urlKey)->exists()) {
                    continue;
                }

                // Cross-feed dedupe: is this the same story we already ran from
                // another feed (or this one under a different GUID)? Checked
                // BEFORE the AI rewrite so duplicates cost nothing.
                $embedding = null;
                $duplicateOf = $this->dedup->findDuplicate($item, $embedding);

                $record = IngestedItem::create([
                    'ingest_source_id' => $source->id,
                    'guid' => $urlKey ?: ($item['guid'] ?? $item['link']),
                    'source_url' => $item['link'],
                    'title' => $item['title'],
                    'status' => $duplicateOf ? 'duplicate' : 'pending',
                    'embedding' => $embedding,
                    'error' => $duplicateOf
                        ? 'Same story already ingested: "' . Str::limit($duplicateOf->title, 120) . '" (item #' . $duplicateOf->id . ')'
                        : null,
                ]);
            } finally {
                $claim->release();
            }

            if ($duplicateOf) {
                // Another outlet running the same story is a strong sign it matters:
                // record the outlet on our post, which lifts its rank in its section.
                $this->countCoverage($duplicateOf, (string) ($item['link'] ?? ''));

                // Multi-source synthesis: rather than discard a second outlet's take
                // on the same story, fold its new facts into the article we already
                // have. Turns "duplicate" into a richer, less-derivative, multi-source
                // piece. Bounded and safe — see the method.
                $this->mergeDuplicateSource($duplicateOf, $item, $record, $source);
                continue;
            }

            // AI EDITORIAL PRE-SCREEN — runs BEFORE the costly fetch/rewrite/image.
            // For auto-publish feeds we ALWAYS score (it's one tiny call and fails
            // OPEN at 100): the 0-100 score is what ranks the queue and decides
            // immediate-vs-hold, so it must always be set. A threshold > 0 then also
            // SKIPS weak/off-brand stories below the bar, cutting cost and volume.
            // Each section is scored on its own scale against its own bar
            // (DailyCap::scoreBar), so World and Story of Hope aren't judged as politics.
            $section = $source->category?->slug;
            $threshold = \App\Support\DailyCap::scoreBar($section);
            $screen = null; // holds the editor score+urgency for the publish decision below
            if ($source->auto_publish) {
                $screen = $this->rewriter->score($item['title'] ?? '', $item['summary'] ?? '', $section);
                $record->editorial_score = $screen['score'];
                $record->save();

                if ($threshold > 0 && $screen['score'] < $threshold) {
                    $record->status = 'skipped';
                    $record->error = 'Below editorial bar (' . $screen['score'] . '/' . $threshold . '): ' . $screen['reason'];
                    $record->save();
                    continue;
                }
            }

            try {
                // Pull the full source article (text for a complete rewrite +
                // the high-res og:image, far better than the RSS thumbnail).
                $page = $this->articles->extract($item['link']);
                $item['full_text'] = $page['text'];

                // When did the ORIGINAL outlet publish? The earliest of the feed date
                // and the article page's published date — never a modified/"updated"
                // date, which outlets bump on edits and which made old stories look new.
                $dates = array_filter([$item['published_at'] ?? null, $this->parseDate($page['published_at'] ?? null)]);
                $sourcePublishedAt = $dates ? min($dates)->copy()->utc() : null;   // one timezone for every outlet
                $skip = match (true) {
                    ! $sourcePublishedAt => 'No publish date in the feed or on the article page — cannot verify freshness.',
                    $sourcePublishedAt->lt($freshCutoff) => 'Stale: the source published it ' . $sourcePublishedAt->diffForHumans() . '.',
                    default => null,
                };
                if ($skip) {
                    $record->update(['status' => 'skipped', 'error' => $skip]);
                    continue;
                }
                $item['source_published_at'] = $sourcePublishedAt;

                $rewritten = $this->rewriter->rewrite(
                    $item,
                    $source->category?->name ?? 'News',
                    $source->name,
                    $catList,
                );

                $urgent = (bool) ($screen['urgent'] ?? false) || (bool) ($rewritten['is_breaking'] ?? false);

                // Quality gate: only substantial articles go live. The floor is the
                // SAME story-type rule the publish gate uses (350 words for breaking,
                // min_publish_words otherwise), so ingest and publishing agree.
                $minWords = \App\Support\PublishGate::minWords($urgent ? 'breaking' : null);
                $wordCount = str_word_count(strip_tags($rewritten['body'] ?? ''));
                $isThin = blank($item['full_text'] ?? null) || $wordCount < $minWords;

                // A short FIRST rewrite of a substantial source (400+ words) is not the
                // final article: it is queued so research and the multi-source writer
                // can produce the full piece. Nothing thin goes live — the publish gate
                // enforces the same floor — but the story is no longer dropped here.
                $expandable = $isThin && str_word_count((string) ($item['full_text'] ?? '')) >= 400;

                // AI chose the best-fit category by content; fall back to the
                // feed's default category if the AI's slug isn't recognized.
                $chosenCat = $categories->firstWhere('slug', $rewritten['category'] ?? null);

                // Safety net for "Story of Hope": a death / tragedy / crime story is
                // never uplifting, whatever the classifier said — file it as US News.
                if ($chosenCat?->slug === 'story-of-hope'
                    && preg_match('/\b(dies|died|dead|death|deaths|killed|kills|obituary|pass(es|ed) away|mourn\w*|funeral|tragedy|tragic|fatal\w*|crash\w*|shooting|murder\w*|suicide|overdose)\b/i',
                        ($rewritten['title'] ?? '') . ' ' . ($rewritten['excerpt'] ?? ''))) {
                    $chosenCat = $categories->firstWhere('slug', 'us-news') ?? $chosenCat;
                }

                $categoryId = $chosenCat?->id ?? $source->category_id;
                $finalSection = $chosenCat?->slug ?? $section;

                $eligible = $source->auto_publish && (! $isThin || $expandable);
                $editorialScore = $screen['score'] ?? null;                 // 0-100, or null if unscored

                // ── Final score: the WRITTEN article, judged in its FINAL section ──
                // The early screen saw only the headline + RSS summary against the
                // feed's section and decides whether a rewrite is worth paying for.
                // This score reads the article itself, in the section it will run in,
                // and is what ranks the story for that section's slots. A failed call
                // keeps the screen score (never a free pass).
                if ($eligible) {
                    $final = $this->rewriter->score(
                        $rewritten['title'] ?? '',
                        trim(($rewritten['excerpt'] ?? '') . "\n\n" . strip_tags($rewritten['body'] ?? '')),
                        $finalSection,
                    );
                    if (empty($final['failed'])) {
                        $editorialScore = $final['score'];
                        $urgent = $urgent || $final['urgent'];
                        $bar = \App\Support\DailyCap::scoreBar($finalSection);
                        if ($editorialScore < $bar) {
                            $eligible = false;   // kept as a draft for the record, never queued
                            $record->error = "Final score {$editorialScore} is below the {$finalSection} bar ({$bar}): {$final['reason']}";
                        }
                    }
                }

                // ── Nothing goes live straight from ingest ──
                // Every news story is researched (gpt-5.4), written from that research
                // (gpt-5.4-mini) and independently fact-checked (gpt-5.4) first. Breaking
                // stories do that at once in the fast lane below (live within minutes);
                // the rest wait for their section's next slot (posts:promote-queued).
                $shouldPublish = false;
                if ($eligible && $isThin && blank($record->error)) {
                    $record->error = "Short first rewrite ({$wordCount} words, needs {$minWords}); queued so research can write the full article.";
                }

                $queue = $eligible;                                         // held for research and its section's next slot

                // A breaking story published straight from ingest still has to clear
                // the structural publish rules (switched-off sections, opinion inside
                // news, placeholders, freshness). If it doesn't, it is held in the
                // queue like any other story instead of going live.
                if ($shouldPublish) {
                    $fails = \App\Support\PublishGate::ingestFailures(new Post([
                        'title' => $rewritten['title'], 'excerpt' => $rewritten['excerpt'], 'body' => $rewritten['body'],
                        'category_id' => $categoryId, 'source_published_at' => $sourcePublishedAt,
                    ]));
                    if ($fails) {
                        $shouldPublish = false;
                        $queue = $eligible;
                        Log::info("Ingest held \"{$rewritten['title']}\" from immediate publishing: " . implode(' ', $fails));
                    }
                }

                // Image handling (only when the source opts in):
                //  - ai_image ON  -> generate an original AI image (safer, no copyright risk)
                //  - ai_image OFF -> copy the best source photo: article og:image first,
                //    then the RSS image; if both fail the quality gate (min 500px),
                //    fall back to an AI original rather than shipping a bad photo.
                // Only pay for the image when the story actually goes live NOW.
                // Queued stories get their image at promotion time (posts:promote-
                // queued) and failed drafts at recovery (posts:fix-drafts), so we
                // never generate an AI image for a story that expires unpublished.
                $featuredImage = null;
                if ($shouldPublish && $source->fetch_images) {
                    $aiPrompt = "Editorial news illustration for a {$source->category?->name} story titled: "
                        . "{$rewritten['title']}. Photorealistic, tasteful, no text, no logos, no watermarks.";

                    if ($source->ai_image) {
                        // AI first; if it blips, fall back to the source photo so
                        // a post is never published without an image.
                        $featuredImage = $this->images->generate($aiPrompt)
                            ?? $this->images->storeFromUrl($page['image'] ?? null)
                            ?? $this->images->storeFromUrl($item['image'] ?? null);
                    } else {
                        $featuredImage = $this->images->storeFromUrl($page['image'] ?? null)
                            ?? $this->images->storeFromUrl($item['image'] ?? null)
                            ?? $this->images->generate($aiPrompt);
                    }
                }

                $post = Post::create([
                    'title' => $rewritten['title'],
                    'slug' => $this->uniqueSlug($rewritten['title']),
                    'excerpt' => $rewritten['excerpt'],
                    'social_text' => $rewritten['social_text'] ?? null,
                    'body' => $rewritten['body'],
                    'takeaways' => \App\Support\ArticleSections::takeaways($rewritten['takeaways'] ?? []) ?: null,
                    'faqs' => ! empty($rewritten['faqs']) ? $rewritten['faqs'] : null,
                    'category_id' => $categoryId,
                    // Byline the journalist who covers this beat; fall back to the
                    // feed's configured author if the category isn't mapped.
                    'author_id' => \App\Support\Bylines::forCategorySlug($chosenCat?->slug) ?? $source->author_id,
                    'featured_image' => $featuredImage,
                    'image_icon' => $chosenCat?->icon ?? $source->category?->icon ?? '📰',
                    'status' => $shouldPublish ? 'published' : 'draft',
                    'published_at' => $shouldPublish ? now() : null,
                    'source_name' => $source->name,
                    'source_url' => $item['link'],
                    'source_published_at' => $sourcePublishedAt,
                    // Outlets (hosts) running this story; other feeds' copies add to it
                    // and lift its rank (StoryRank::coverageBoost).
                    'coverage_outlets' => array_values(array_filter([self::outlet($item['link'] ?? '')])),
                    // First contributing outlet; multi-source merge appends more.
                    'sources' => [['name' => $source->name, 'url' => $item['link']]],
                    // SEO fields the rewrite now produces in the same call (keyword +
                    // meta), so a new post is search-optimized from birth. optimizePost
                    // (below) only fills blanks, so these are preserved.
                    'focus_keyword' => $rewritten['focus_keyword'] ?? null,
                    'meta_title' => $rewritten['meta_title'] ?? null,
                    'meta_description' => $rewritten['meta_description'] ?? null,
                    // Editorial priority score + queue marker (see selection logic above).
                    'editorial_score' => $editorialScore,
                    'queued_at' => $queue ? now() : null,
                    // AI editorial placement — top story feeds the hero, breaking
                    // feeds the ticker (12h), trending feeds the trending strip (48h).
                    'is_featured' => (bool) ($rewritten['is_top_story'] ?? false),
                    // Breaking when EITHER the editor's screen called it urgent or the
                    // writer flagged it — so a major story always gets the fast lane.
                    'is_breaking' => $urgent,
                    'breaking_until' => $urgent ? now()->addHours(12) : null,
                    'is_trending' => (bool) ($rewritten['is_trending'] ?? false),
                    'trending_until' => ($rewritten['is_trending'] ?? false) ? now()->addHours(48) : null,
                    // Open comments automatically on Opinion pieces only.
                    'allow_comments' => $source->category?->slug === 'opinion',
                ]);

                // Topic tags → evergreen hub pages (/topic/{slug}) for internal
                // linking + durable long-tail SEO. Free: came from the rewrite call.
                if (! empty($rewritten['tags'])) {
                    $post->syncTagsFromNames($rewritten['tags']);
                }

                $record->update(['status' => 'processed', 'post_id' => $post->id]);

                // Auto-SEO: optimize every AI-created post immediately (apply
                // suggested meta + score). Failure must never fail the post.
                try {
                    $this->seo->optimizePost($post);
                    // Guarantee search-readiness: if a story that just went LIVE still
                    // scores under 80, boost it now (keyword/H2s/readability/links).
                    // Queued drafts are boosted at promotion time instead, so we never
                    // pay to boost a story that expires unpublished.
                    if ($shouldPublish && (int) ($post->seo_score ?? 0) < 80) {
                        $this->booster->boost($post);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Auto-SEO failed for post {$post->id}: " . $e->getMessage());
                }

                // BREAKING news → fast lane, right now: research other outlets, write
                // the multi-source article, check it and publish in this same run, so
                // it goes live within minutes instead of waiting for the 10-min
                // synthesis and 30-min promotion jobs. If other outlets don't have it
                // yet, posts:breaking retries every minute.
                if ($urgent && $post->status === 'draft') {
                    try {
                        $result = app(\App\Services\BreakingPublisher::class)->attempt($post);
                        Log::info("Breaking fast lane #{$post->id}: {$result}");
                    } catch (\Throwable $e) {
                        Log::warning("Breaking fast lane failed for post {$post->id}: " . $e->getMessage());
                    }
                }

                $created++;
            } catch (\Throwable $e) {
                $record->update(['status' => 'failed', 'error' => $e->getMessage()]);
                Log::warning("Ingest rewrite failed for item {$record->id}: " . $e->getMessage());
            }
        }

        $source->update(['last_fetched_at' => now()]);

        return $created;
    }

    /**
     * Fold a second outlet's report on the same story into the article we already
     * published — multi-source synthesis. Bounded so it's safe and cheap: only real,
     * recent articles, a per-story source cap, no repeated outlets, and never shrinks
     * the body. On success the item is logged as 'merged' instead of 'duplicate'.
     */
    private function mergeDuplicateSource(IngestedItem $duplicateOf, array $item, IngestedItem $record, IngestSource $source): void
    {
        if (! (bool) \App\Models\Setting::get('multi_source_synthesis', true)) {
            return;
        }

        $post = $duplicateOf->post_id ? Post::find($duplicateOf->post_id) : null;
        if (! $post) {
            return; // matched an item with no post (e.g. a skipped one) — nothing to enrich
        }

        // Only enrich REAL, RECENT articles — published or queued, not failed drafts,
        // and within the merge window (re-editing old stories isn't worth it).
        $isReal = $post->status === 'published' || filled($post->queued_at);
        $anchor = $post->published_at ?? $post->created_at;
        $windowH = (float) \App\Models\Setting::get('merge_window_hours', 48);
        if (! $isReal || ($anchor && $anchor->lt(now()->subHours($windowH)))) {
            return;
        }

        // Cap how many outlets we fold into one story, and never add the same one twice.
        $sources = is_array($post->sources) ? $post->sources : [];
        if (count($sources) >= (int) \App\Models\Setting::get('max_sources_per_post', 4)) {
            return;
        }
        $newKey = $this->dedupKey($item['link'] ?? '');
        foreach ($sources as $s) {
            if ($newKey !== '' && $this->dedupKey($s['url'] ?? '') === $newKey) {
                return;
            }
        }

        try {
            $text = $this->articles->extract($item['link'])['text'] ?? '';
            if (blank($text) || str_word_count($text) < 120) {
                return; // nothing substantial to synthesize
            }

            $merged = $this->rewriter->mergeSource($post->title, (string) $post->body, $text, $source->name);
            if (! $merged) {
                return;
            }
            // A merge must ADD, never shrink — guard against content loss.
            if (str_word_count(strip_tags($merged)) < str_word_count(strip_tags((string) $post->body))) {
                return;
            }
            // A LIVE article only changes when the edit passes the independent
            // fact-check and originality rules. (Queued drafts are re-written and
            // checked by the pipeline before they can publish anyway.)
            if ($post->status === 'published') {
                $pipeline = app(\App\Services\EditorialPipeline::class);
                $new = ['text' => $text] + $this->research->sourceRecord($item['link'], $this->articles->extract($item['link']));
                if (! $pipeline->acceptUpdate($post, $merged, $pipeline->recordedEvidence($post, [$new]))) {
                    return;
                }
            }

            $sources[] = ['name' => $source->name, 'url' => $item['link']];
            $post->forceFill(['body' => $merged, 'sources' => $sources])->save();

            // Body changed — refresh links + SEO score.
            try {
                $this->seo->optimizePost($post);
            } catch (\Throwable) {
            }

            $record->update([
                'status' => 'merged',
                'post_id' => $post->id,
                'error' => 'Synthesized into "' . Str::limit($post->title, 100) . '" (post #' . $post->id . ')',
            ]);
        } catch (\Throwable $e) {
            Log::warning("Source merge failed for item {$record->id}: " . $e->getMessage());
        }
    }

    /**
     * Stable dedupe key for an article: the URL with query string and fragment
     * stripped (feeds append rotating tracking params) and any trailing slash
     * removed. This uniquely identifies an article across polls and feeds.
     */
    /** Add this outlet to the coverage of the post the duplicate belongs to (once per outlet). */
    private function countCoverage(IngestedItem $original, string $link): void
    {
        $post = $original->post_id ? Post::find($original->post_id) : null;
        $outlet = self::outlet($link);
        if (! $post || $outlet === '') {
            return;
        }
        $outlets = (array) ($post->coverage_outlets ?: array_filter([self::outlet((string) $post->source_url)]));
        if (in_array($outlet, $outlets, true)) {
            return;   // e.g. Fox US and Fox World carrying the same Fox story = one outlet
        }
        $outlets[] = $outlet;
        $post->forceFill(['coverage_outlets' => array_values($outlets)])->saveQuietly();
    }

    /** "https://www.foxnews.com/politics/x" → "foxnews.com". */
    private static function outlet(string $url): string
    {
        return strtolower(preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)));
    }

    private function parseDate(?string $raw): ?\Illuminate\Support\Carbon
    {
        try {
            return filled($raw) ? \Illuminate\Support\Carbon::parse($raw)->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dedupKey(string $url): string
    {
        $url = preg_replace('/[?#].*$/', '', trim($url));

        return rtrim($url, '/');
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'story';
        $slug = $base;
        $i = 2;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
