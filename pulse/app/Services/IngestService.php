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

        foreach ($items as $item) {
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
                continue;
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

                // AI chose the best-fit category by content; fall back to the
                // feed's default category if the AI's slug isn't recognized.
                $chosenCat = $categories->firstWhere('slug', $rewritten['category'] ?? null);
                $categoryId = $chosenCat?->id ?? $source->category_id;

                // Image handling (only when the source opts in):
                //  - ai_image ON  -> generate an original AI image (safer, no copyright risk)
                //  - ai_image OFF -> copy the best source photo: article og:image first,
                //    then the RSS image; if both fail the quality gate (min 500px),
                //    fall back to an AI original rather than shipping a bad photo.
                $featuredImage = null;
                if ($source->fetch_images) {
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
                    'category_id' => $categoryId,
                    'author_id' => $source->author_id,
                    'featured_image' => $featuredImage,
                    'image_icon' => $chosenCat?->icon ?? $source->category?->icon ?? '📰',
                    'status' => $source->auto_publish ? 'published' : 'draft',
                    'published_at' => $source->auto_publish ? now() : null,
                    'source_name' => $source->name,
                    'source_url' => $item['link'],
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

                $record->update(['status' => 'processed', 'post_id' => $post->id]);

                // Auto-SEO: optimize every AI-created post immediately (apply
                // suggested meta + score). Failure must never fail the post.
                try {
                    $this->seo->optimizePost($post);
                } catch (\Throwable $e) {
                    Log::warning("Auto-SEO failed for post {$post->id}: " . $e->getMessage());
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
