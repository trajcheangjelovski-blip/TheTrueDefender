<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;

/**
 * Replace an exact substring in ONE post's body + excerpt. Targeted and
 * auditable (positional args, no arbitrary eval); reversible by running it
 * again with the search/replace swapped. Use for precise copy fixes such as
 * a duplicated phrase the AI produced.
 *
 *   php artisan posts:replace-text {slug|id} "old text" "new text"
 *   php artisan posts:replace-text {slug|id} "old text" "new text" --dry
 */
class PostsReplaceText extends Command
{
    protected $signature = 'posts:replace-text
        {ref : Post id or slug}
        {search : Exact text to find (or base64 with --b64)}
        {replace : Replacement text (or base64 with --b64)}
        {--b64 : Treat search/replace as base64 (avoids shell-quoting issues with spaces)}
        {--dry : Report occurrences without writing}';

    protected $description = 'Replace an exact substring in one post\'s body/excerpt (targeted, reversible).';

    public function handle(): int
    {
        $ref = $this->argument('ref');
        $search = $this->argument('search');
        $replace = $this->argument('replace');

        if ($this->option('b64')) {
            $search = base64_decode($search, true) ?: $search;
            $replace = base64_decode($replace, true) ?: $replace;
        }

        $post = is_numeric($ref) ? Post::find((int) $ref) : Post::where('slug', $ref)->first();
        if (! $post) {
            $this->error("Post not found: {$ref}");

            return self::FAILURE;
        }

        $bodyN = substr_count((string) $post->body, $search);
        $exN = substr_count((string) $post->excerpt, $search);

        $this->line("Post #{$post->id}  /post/{$post->slug}");
        $this->line("Occurrences — body: {$bodyN}, excerpt: {$exN}");

        if ($bodyN + $exN === 0) {
            $this->warn('Nothing to replace.');

            return self::SUCCESS;
        }
        if ($this->option('dry')) {
            $this->warn('[DRY RUN] no changes written.');

            return self::SUCCESS;
        }

        $post->body = str_replace($search, $replace, (string) $post->body);
        $post->excerpt = str_replace($search, $replace, (string) $post->excerpt);
        $post->saveQuietly();

        $this->info('Replaced. Reverse by swapping the arguments.');

        return self::SUCCESS;
    }
}
