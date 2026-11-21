<?php

namespace App\Services;

use App\Models\IngestedItem;
use App\Models\IngestSource;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // Discovery is independent of the GPU. Every accepted URL is persisted atomically.
        try {
            $items = $this->reader->read($source->feed_url, $source->max_items);
        } catch (\Throwable $e) {
            Log::warning('Feed retrieval failed', ['source_id' => $source->id, 'error' => get_class($e)]);

            return 0;
        }
        $created = 0;
        foreach ($items as $item) {
            $url = (string) ($item['link'] ?? '');
            $key = $this->dedupKey($url);
            if (! $key || IngestedItem::where('guid', $key)->exists()) {
                continue;
            }
            $page = $this->articles->extract($url);
            $dates = array_filter([$item['published_at'] ?? null, $this->parseDate($page['published_at'] ?? null)]);
            $published = $dates ? min($dates)->copy()->utc() : null;
            // Unknown/old dates are held by the editorial pipeline, never replaced by an updated timestamp.
            $evidence = ['text' => (string) ($page['text'] ?? ''), 'name' => $source->name, 'url' => $url,
                'title' => $page['title'] ?? $item['title'], 'published_at' => $published?->toIso8601String(),
                'accessed_at' => now()->toIso8601String(), 'modified_at' => $page['modified_at'] ?? null];
            $lock = Cache::lock('ingest:dedupe', 60);
            try {
                $lock->block(10);
                if (IngestedItem::where('guid', $key)->exists()) {
                    continue;
                }
                DB::transaction(function () use ($source, $item, $key, $url, $published, $evidence, &$created) {
                    $cutoff = ResearchAnalyst::isHumanInterest($source->category?->slug)
                        ? now()->subDays(ResearchAnalyst::HUMAN_INTEREST_DAYS)
                        : now()->subHours((float) Setting::get('ingest_max_age_hours', 3));
                    $reason = ! $published ? 'No publish date; held for review.'
                        : ($published->lt($cutoff) ? 'Stale source publication date.' : ($published->gt(now()->addMinutes(5)) ? 'Future source date; held for review.' : null));
                    if ($reason) {
                        IngestedItem::create(['ingest_source_id' => $source->id, 'guid' => $key, 'source_url' => $url,
                            'title' => $item['title'], 'status' => 'skipped', 'error' => $reason, 'evidence' => $evidence]);

                        return;
                    }
                    $post = Post::create([
                        'title' => $item['title'], 'slug' => $this->uniqueSlug($item['title']),
                        'excerpt' => null, 'body' => '', 'status' => 'draft',
                        'category_id' => $source->category_id, 'author_id' => $source->author_id,
                        'image_icon' => $source->category?->icon ?? '📰',
                        'source_name' => $source->name, 'source_url' => $url, 'source_published_at' => $published,
                        'sources' => [['name' => $source->name, 'url' => $url]], 'queued_at' => now(),
                        'review_status' => 'needs_research', 'allow_comments' => false,
                        'is_breaking' => (bool) preg_match('/\b(breaking|evacuation order|state of emergency)\b/i', (string) $item['title']),
                        'truth_profile' => Setting::get('truth_default_profile'),
                    ]);
                    IngestedItem::create(['ingest_source_id' => $source->id, 'guid' => $key,
                        'source_url' => $url, 'title' => $item['title'], 'status' => 'processed',
                        'post_id' => $post->id, 'evidence' => $evidence]);
                    $created++;
                });
            } finally {
                $lock->release();
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
        if (! (bool) Setting::get('multi_source_synthesis', true)) {
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
        $windowH = (float) Setting::get('merge_window_hours', 48);
        if (! $isReal || ($anchor && $anchor->lt(now()->subHours($windowH)))) {
            return;
        }

        // Cap how many outlets we fold into one story, and never add the same one twice.
        $sources = is_array($post->sources) ? $post->sources : [];
        if (count($sources) >= (int) Setting::get('max_sources_per_post', 4)) {
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
                $pipeline = app(EditorialPipeline::class);
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
                'error' => 'Synthesized into "'.Str::limit($post->title, 100).'" (post #'.$post->id.')',
            ]);
        } catch (\Throwable $e) {
            Log::warning("Source merge failed for item {$record->id}: ".$e->getMessage());
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

    private function parseDate(?string $raw): ?Carbon
    {
        try {
            return filled($raw) ? Carbon::parse($raw)->utc() : null;
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
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
