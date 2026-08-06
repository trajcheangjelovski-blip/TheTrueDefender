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
        {--hours=3 : Drafts newer than this are reprocessed+published; older ones are deleted}
        {--min-words=400 : Word floor for publishing}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Delete stale ingest drafts; reprocess recent ones with the improved fetcher and publish the substantial ones.';

    public function handle(ArticleFetcher $articles, Rewriter $rewriter): int
    {
        $cutoff = now()->subHours((float) $this->option('hours'));
        $min = (int) $this->option('min-words');
        $dry = (bool) $this->option('dry');

        // Only touch AI-ingested drafts (have a source_url) — never hand-written ones.
        $base = fn () => Post::where('status', 'draft')->whereNotNull('source_url');

        // ── 1. Delete stale drafts (older than the window) ──
        $old = (clone $base())->where('created_at', '<', $cutoff)->get();
        $this->info(($dry ? '[DRY] ' : '') . "Deleting {$old->count()} stale draft(s) older than {$this->option('hours')}h…");
        if (! $dry) {
            foreach ($old as $p) {
                $p->delete(); // post_tag pivot cascades
            }
        }

        // ── 2. Reprocess recent drafts, publish the substantial ones ──
        $recent = (clone $base())->where('created_at', '>=', $cutoff)->get();
        $this->info(($dry ? '[DRY] ' : '') . "Reprocessing {$recent->count()} recent draft(s)…");

        $published = $stillThin = $failed = 0;

        foreach ($recent as $post) {
            if ($dry) {
                $this->line('#' . $post->id . '  ' . Str::limit($post->title, 55));
                continue;
            }

            try {
                $full = $articles->extract($post->source_url)['text'] ?? '';
                if (blank($full) || str_word_count($full) < 120) {
                    $this->line("#{$post->id}  source still unavailable — left as draft");
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
        $this->info(($dry ? '[DRY] ' : '') . "Deleted {$old->count()} old · published {$published} · left thin {$stillThin} · failed {$failed}.");

        return self::SUCCESS;
    }
}
