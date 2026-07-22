<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\ArticleSanitizer;
use Illuminate\Console\Command;

class PostsSanitize extends Command
{
    protected $signature = 'posts:sanitize {--dry : Report what would change without saving}';

    protected $description = 'Strip AI/SEO meta-commentary from existing post bodies, excerpts and captions.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $changed = 0;

        foreach (Post::query()->get() as $post) {
            $newBody = ArticleSanitizer::clean($post->body);
            $newExcerpt = ArticleSanitizer::cleanText($post->excerpt);
            $newSocial = ArticleSanitizer::cleanText($post->social_text);

            $dirty = $newBody !== (string) $post->body
                || $newExcerpt !== (string) $post->excerpt
                || $newSocial !== (string) $post->social_text;

            if (! $dirty) {
                continue;
            }

            $changed++;
            $this->line("#{$post->id}  " . \Illuminate\Support\Str::limit($post->title, 60));

            if (! $dry) {
                $post->forceFill([
                    'body' => $newBody,
                    'excerpt' => $newExcerpt,
                    'social_text' => $newSocial ?: null,
                ])->saveQuietly();
            }
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Cleaned {$changed} post(s).");

        return self::SUCCESS;
    }
}
