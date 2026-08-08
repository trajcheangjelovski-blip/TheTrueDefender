<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ArticleFetcher;
use App\Services\Rewriter;
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

    public function handle(ArticleFetcher $articles, Rewriter $rewriter): int
    {
        $reproCutoff = now()->subHours((float) $this->option('reprocess-hours'));
        $deleteHours = (float) $this->option('delete-hours');
        $maxAttempts = (int) $this->option('max-attempts');
        $min = (int) $this->option('min-words');
        $dry = (bool) $this->option('dry');

        // Only touch AI-ingested drafts (have a source_url) — never hand-written ones.
        $base = fn () => Post::where('status', 'draft')->whereNotNull('source_url');

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

        $published = $stillThin = $failed = 0;

        foreach ($recent as $post) {
            if ($dry) {
                $this->line('#' . $post->id . "  (attempt {$post->fix_attempts})  " . Str::limit($post->title, 50));
                continue;
            }

            // Count the attempt up front so an un-fixable draft (e.g. a video page)
            // stops being retried after --max-attempts instead of forever.
            $post->forceFill(['fix_attempts' => $post->fix_attempts + 1])->saveQuietly();

            try {
                $full = $articles->extract($post->source_url)['text'] ?? '';
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

                $post->forceFill([
                    'body' => $rw['body'],
                    'excerpt' => $rw['excerpt'] ?: $post->excerpt,
                    'social_text' => $rw['social_text'] ?: $post->social_text,
                    'takeaways' => ! empty($rw['takeaways']) ? $rw['takeaways'] : $post->takeaways,
                    'faqs' => ! empty($rw['faqs']) ? $rw['faqs'] : $post->faqs,
                    'status' => 'published',
                    'published_at' => now(),
                ])->save(); // normal save → PostObserver fires push/social for the fresh story

                if (! empty($rw['tags'])) {
                    $post->syncTagsFromNames($rw['tags']);
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
        $this->info(($dry ? '[DRY] ' : '') . "Deleted {$deleted} old · published {$published} · left thin {$stillThin} · failed {$failed}.");

        return self::SUCCESS;
    }
}
