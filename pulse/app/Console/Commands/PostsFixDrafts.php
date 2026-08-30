<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ArticleFetcher;
use App\Services\ImageService;
use App\Services\Rewriter;
use App\Services\SeoBooster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostsFixDrafts extends Command
{
    protected $signature = 'posts:fix-drafts
        {--reprocess-hours=24 : Reprocess drafts newer than this many hours}
        {--delete-hours=0 : Delete drafts older than this many hours (0 = never delete)}
        {--max-attempts=4 : Skip drafts already retried this many times}
        {--min-words=400 : Word floor for publishing}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Reprocess recent ingest drafts (re-fetch + rewrite) and publish the substantial ones; optionally delete stale un-fixable drafts. Runs every 5 min as a fast retry after a failed publish.';

    public function handle(ArticleFetcher $articles, Rewriter $rewriter, ImageService $images, SeoBooster $booster): int
    {
        $reproCutoff = now()->subHours((float) $this->option('reprocess-hours'));
        $deleteHours = (float) $this->option('delete-hours');
        $maxAttempts = (int) $this->option('max-attempts');
        $min = (int) $this->option('min-words');
        $dry = (bool) $this->option('dry');

        // Only touch AI-ingested drafts (have a source_url) — never hand-written ones,
        // and NEVER the editorial queue (queued_at set): those are good stories held
        // on purpose for paced promotion, owned by posts:promote-queued, not failures.
        $base = fn () => Post::where('status', 'draft')->whereNotNull('source_url')->whereNull('queued_at');

        // ── 1. Delete stale drafts (only when a delete window is set) ──
        $deleted = 0;
        if ($deleteHours > 0) {
            $old = (clone $base())->where('created_at', '<', now()->subHours($deleteHours))->get();
            $deleted = $old->count();
            $this->info(($dry ? '[DRY] ' : '') . "Deleting {$deleted} stale draft(s) older than {$deleteHours}h…");
            if (! $dry) {
                foreach ($old as $p) {
                    $p->delete(); // post_tag pivot cascades
                }
            }
        }

        // ── 2. Reprocess recent drafts that haven't exhausted their retries ──
        $recent = (clone $base())
            ->where('created_at', '>=', $reproCutoff)
            ->where('fix_attempts', '<', $maxAttempts)
            ->get();
        $this->info(($dry ? '[DRY] ' : '') . "Reprocessing {$recent->count()} recent draft(s)…");

        $published = $stillThin = $failed = $capped = 0;

        // Respect the HARD daily cap — recovered drafts count toward the same 12/day.
        $cap = (int) \App\Models\Setting::get('daily_publish_cap', 0);
        $capRemaining = $cap <= 0 ? PHP_INT_MAX : max(0, $cap - Post::where('status', 'published')
            ->whereNotNull('source_name')->whereDate('published_at', today())->count());

        foreach ($recent as $post) {
            if ($dry) {
                $this->line('#' . $post->id . "  (attempt {$post->fix_attempts})  " . Str::limit($post->title, 50));
                continue;
            }

            // Count the attempt up front so an un-fixable draft (e.g. a video page)
            // stops being retried after --max-attempts instead of forever.
            $post->forceFill(['fix_attempts' => $post->fix_attempts + 1])->saveQuietly();

            try {
                $page = $articles->extract($post->source_url);
                $full = $page['text'] ?? '';
                if (blank($full) || str_word_count($full) < 120) {
                    $this->line("#{$post->id}  source still unavailable — left as draft (attempt {$post->fix_attempts})");
                    $stillThin++;
                    continue;
                }

                $rw = $rewriter->rewrite(
                    ['title' => $post->title, 'summary' => (string) $post->excerpt, 'link' => $post->source_url, 'full_text' => $full],
                    $post->category?->name ?? 'News',
                    $post->source_name ?? 'source',
                    [],
                );

                $newWords = str_word_count(strip_tags($rw['body'] ?? ''));
                if ($newWords < $min) {
                    $this->line("#{$post->id}  still thin ({$newWords}w) — left as draft");
                    $stillThin++;
                    continue;
                }

                // Hard daily cap: hold recovered drafts once today's total is reached.
                if ($capRemaining <= 0) {
                    $this->line("#{$post->id}  daily cap reached — left as draft");
                    $capped++;
                    continue;
                }

                // Image deferred from ingest (we don't image un-published drafts):
                // give this now-substantial story a picture before it goes live.
                // AI-first (matches sources' ai_image), falling back to the source photo.
                $imageId = $post->featured_image;
                if (blank($imageId)) {
                    $prompt = 'Editorial news illustration for a ' . ($post->category?->name ?? 'news')
                        . " story titled: {$post->title}. Photorealistic, tasteful, no text, no logos, no watermarks.";
                    $imageId = $images->generate($prompt) ?: $images->storeFromUrl($page['image'] ?? null);
                }

                $post->forceFill([
                    'body' => $rw['body'],
                    'excerpt' => $rw['excerpt'] ?: $post->excerpt,
                    'social_text' => $rw['social_text'] ?: $post->social_text,
                    'takeaways' => ! empty($rw['takeaways']) ? $rw['takeaways'] : $post->takeaways,
                    'faqs' => ! empty($rw['faqs']) ? $rw['faqs'] : $post->faqs,
                    'featured_image' => $imageId,
                    'status' => 'published',
                    'published_at' => now(),
                ])->save(); // normal save → PostObserver fires push/social for the fresh story
                $capRemaining--;

                if (! empty($rw['tags'])) {
                    $post->syncTagsFromNames($rw['tags']);
                }

                // SEO-optimize + guarantee search-readiness for the freshly published story.
                try {
                    $seo = app(\App\Services\SeoOptimizer::class)->optimizePost($post);
                    if ((int) ($seo['score'] ?? 0) < 80) {
                        $booster->boost($post);
                    }
                } catch (\Throwable) {
                    // non-fatal
                }

                $this->info("#{$post->id}  published ({$newWords}w)  " . Str::limit($post->title, 50));
                $published++;
            } catch (\Throwable $e) {
                Log::warning("posts:fix-drafts failed #{$post->id}: " . $e->getMessage());
                $this->warn("#{$post->id}  failed: " . $e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Deleted {$deleted} old · published {$published} · left thin {$stillThin} · held (cap) {$capped} · failed {$failed}.");

        return self::SUCCESS;
    }
}
