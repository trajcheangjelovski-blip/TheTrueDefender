<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ArticleFetcher;
use App\Services\Rewriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PostsExpand extends Command
{
    protected $signature = 'posts:expand
        {--dry : List what would change, no fetching/AI}
        {--limit=25 : How many thin posts to process this run}
        {--min-words=400 : Word floor for a "substantial" article}';

    protected $description = 'Re-write thin published articles into substantial ones; unpublish any that can\'t be expanded.';

    public function handle(ArticleFetcher $articles, Rewriter $rewriter): int
    {
        $min = (int) $this->option('min-words');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        // Thin, published, and re-writable (has a source article to expand from).
        $thin = Post::where('status', 'published')->whereNotNull('source_url')->get()
            ->filter(fn (Post $p) => str_word_count(strip_tags($p->body)) < $min)
            ->take($limit);

        $expanded = $unpublished = $failed = 0;

        foreach ($thin as $post) {
            $words = str_word_count(strip_tags($post->body));

            if ($dry) {
                $this->line("#{$post->id}  {$words}w  " . \Illuminate\Support\Str::limit($post->title, 55));
                continue;
            }

            try {
                $page = $articles->extract($post->source_url);
                $full = $page['text'] ?? '';

                if (blank($full) || str_word_count($full) < 120) {
                    $this->unpublish($post, 'source unavailable/short');
                    $unpublished++;
                    continue;
                }

                $rw = $rewriter->rewrite(
                    ['title' => $post->title, 'summary' => $post->excerpt, 'link' => $post->source_url, 'full_text' => $full],
                    $post->category?->name ?? 'News',
                    $post->source_name ?? 'source',
                    [],
                );

                $newWords = str_word_count(strip_tags($rw['body'] ?? ''));
                if ($newWords >= $min) {
                    // Keep title/slug/category/image/flags — only deepen the text.
                    $post->forceFill([
                        'body' => $rw['body'],
                        'excerpt' => $rw['excerpt'] ?: $post->excerpt,
                        'social_text' => $rw['social_text'] ?: $post->social_text,
                    ])->saveQuietly();
                    $this->info("#{$post->id}  {$words}w -> {$newWords}w  expanded");
                    $expanded++;
                } else {
                    $this->unpublish($post, "still thin ({$newWords}w)");
                    $unpublished++;
                }
            } catch (\Throwable $e) {
                Log::warning("posts:expand failed #{$post->id}: " . $e->getMessage());
                $this->warn("#{$post->id}  failed: " . $e->getMessage());
                $failed++;
            }
        }

        $remaining = Post::where('status', 'published')->whereNotNull('source_url')->get()
            ->filter(fn (Post $p) => str_word_count(strip_tags($p->body)) < $min)->count();

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Expanded {$expanded}, unpublished {$unpublished}, failed {$failed}. Thin published remaining: {$remaining}.");

        return self::SUCCESS;
    }

    private function unpublish(Post $post, string $why): void
    {
        $post->forceFill(['status' => 'draft', 'published_at' => null])->saveQuietly();
        $this->line("#{$post->id}  unpublished ({$why})");
    }
}
