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
        // than this window (default 3h). Items with no date are allowed through.
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
            $exists = $urlKey !== '' && IngestedItem::where('guid', $urlKey)->exists();
            if ($exists) {
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

            if ($duplicateOf) {
                // Multi-source synthesis: rather than discard a second outlet's take
                // on the same story, fold its new facts into the article we already
                // have. Turns "duplicate" into a richer, less-derivative, multi-source
                // piece. Bounded and safe — see the method.
                $this->mergeDuplicateSource($duplicateOf, $item, $record, $source);
                continue;
            }

            // AI EDITORIAL PRE-SCREEN — runs BEFORE the costly fetch/rewrite/image.
            // Only auto-publish feeds are gated, and only when a threshold is set
            // (publish_score_threshold = 0 → dormant, nothing changes). Weak/
            // off-brand stories are skipped for the price of one tiny scoring call,
            // so both cost and daily volume drop. Scoring fails OPEN (score 100).
            $threshold = (int) \App\Models\Setting::get('publish_score_threshold', 0);
            $screen = null; // holds the editor score+urgency for the publish decision below
            if ($threshold > 0 && $source->auto_publish) {
                $screen = $this->rewriter->score($item['title'] ?? '', $item['summary'] ?? '');
                $record->editorial_score = $screen['score'];

                if ($screen['score'] < $threshold) {
                    $record->status = 'skipped';
                    $record->error = 'Below editorial bar (' . $screen['score'] . '/' . $threshold . '): ' . $screen['reason'];
                    $record->save();
                    continue;
                }
                $record->save();
            }

            try {
                // Pull the full source article (text for a complete rewrite +
                // the high-res og:image, far better than the RSS thumbnail).
                $page = $this->articles->extract($item['link']);
                $item['full_text'] = $page['text'];

                $rewritten = $this->rewriter->rewrite(
                    $item,
                    $source->category?->name ?? 'News',
                    $source->name,
                    $catList,
                );

                // Quality gate: only auto-publish substantial, original articles.
                // Thin pieces (snippet-only, or below the word floor) are held as
                // drafts for review so the site never ships low-value content.
                $minWords = (int) \App\Models\Setting::get('min_publish_words', 400);
                $wordCount = str_word_count(strip_tags($rewritten['body'] ?? ''));
                $isThin = blank($item['full_text'] ?? null) || $wordCount < $minWords;

                // ── Editorial selection: topic-priority + two-speed publishing ──
                // Each eligible (auto-publish, substantial) story carries the AI
                // managing-editor priority score (US politics highest, then major
                // disasters). Then:
                //  • URGENT stories (breaking major politics/disaster, or a very high
                //    score) publish IMMEDIATELY — breaking news never waits, and it is
                //    allowed past the daily cap so a big news day is covered.
                //  • Everything else is QUEUED (held as a marked draft) so it can be
                //    compared against the stories that arrive next; posts:promote-queued
                //    later publishes the best of the queue into the day's remaining
                //    slots, or waits if nothing clears the bar.
                $eligible = $source->auto_publish && ! $isThin;
                $editorialScore = $screen['score'] ?? null;                 // 0-100, or null if unscored
                $urgent = (bool) ($screen['urgent'] ?? false) || (bool) ($rewritten['is_breaking'] ?? false);

                $cap = (int) \App\Models\Setting::get('daily_publish_cap', 0);
                $publishedToday = Post::where('status', 'published')
                    ->whereNotNull('source_name')                           // ingested (auto) posts only
                    ->whereDate('published_at', today())
                    ->count();
                $underCap = $cap <= 0 || $publishedToday < $cap;

                $publishNowScore = (int) \App\Models\Setting::get('publish_now_score', 80);
                $scoreQualifies = $editorialScore === null || $editorialScore >= $publishNowScore;

                // HARD daily cap: NOTHING auto-publishes past the cap today — breaking
                // included. Urgent still jumps ahead of ordinary stories while slots
                // remain; once the cap is hit, even a breaking story is held (queued)
                // rather than published, so the daily total never exceeds the cap.
                $shouldPublish = $eligible && $underCap && ($urgent || $scoreQualifies);
                $queue = $eligible && ! $shouldPublish;                     // held for paced promotion

                // AI chose the best-fit category by content; fall back to the
                // feed's default category if the AI's slug isn't recognized.
                $chosenCat = $categories->firstWhere('slug', $rewritten['category'] ?? null);
                $categoryId = $chosenCat?->id ?? $source->category_id;

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
                    'takeaways' => ! empty($rewritten['takeaways']) ? $rewritten['takeaways'] : null,
                    'faqs' => ! empty($rewritten['faqs']) ? $rewritten['faqs'] : null,
                    'category_id' => $categoryId,
                    'author_id' => $source->author_id,
                    'featured_image' => $featuredImage,
                    'image_icon' => $chosenCat?->icon ?? $source->category?->icon ?? '📰',
                    'status' => $shouldPublish ? 'published' : 'draft',
                    'published_at' => $shouldPublish ? now() : null,
                    'source_name' => $source->name,
                    'source_url' => $item['link'],
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
                    'is_breaking' => (bool) ($rewritten['is_breaking'] ?? false),
                    'breaking_until' => ($rewritten['is_breaking'] ?? false) ? now()->addHours(12) : null,
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

                // BREAKING news: research the web + add an original analysis take
                // RIGHT NOW, before the story sits. A single-source bombshell (e.g.
                // "Trump is in jail") gets cross-checked against other outlets and
                // shipped immediately as a unique, multi-source, analyzed piece.
                if ($shouldPublish && $urgent) {
                    $this->enrichBreaking($post);
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
     * Breaking-news enrichment, run inline at publish for urgent stories:
     *  1) search the open web for other outlets' coverage and synthesize it in
     *     (multi-source + a cross-check against a single-source bombshell), then
     *  2) append a clearly-labeled original ANALYSIS take.
     * Best-effort — any failure leaves the already-published story intact.
     */
    private function enrichBreaking(Post $post): void
    {
        try {
            if ($this->research->enabled()) {
                $sources = is_array($post->sources) ? $post->sources : [];
                $hosts = collect($sources)
                    ->map(fn ($s) => Str::lower((string) parse_url($s['url'] ?? '', PHP_URL_HOST)))
                    ->map(fn ($h) => Str::startsWith($h, 'www.') ? substr($h, 4) : $h)
                    ->filter()->all();
                $room = max(0, (int) \App\Models\Setting::get('max_sources_per_post', 4) - count($sources));

                foreach ($this->research->research($post->title, $hosts, $room) as $src) {
                    $merged = $this->rewriter->mergeSource($post->title, (string) $post->body, $src['text'], $src['name']);
                    if ($merged && str_word_count(strip_tags($merged)) >= str_word_count(strip_tags((string) $post->body))) {
                        $post->body = $merged;
                        $sources[] = ['name' => $src['name'], 'url' => $src['url']];
                    }
                }
                $post->sources = $sources;
            }
            $post->web_researched_at = now();

            // Original analysis take (labeled), appended below the reporting.
            if (! Str::contains(Str::lower((string) $post->body), '<h2>analysis')) {
                $an = $this->rewriter->analysis($post->title, (string) $post->body);
                if ($an) {
                    $post->body = (string) $post->body . $an;
                }
            }
            $post->save();

            // Body changed — refresh links + SEO score.
            try {
                $this->seo->optimizePost($post);
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            Log::warning("Breaking enrich failed for post {$post->id}: " . $e->getMessage());
        }
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
