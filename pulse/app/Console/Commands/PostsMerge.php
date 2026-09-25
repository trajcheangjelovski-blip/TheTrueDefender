<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRedirect;
use Illuminate\Console\Command;

/**
 * Consolidate a duplicate article into a surviving one: the retired article is
 * unpublished (its row is KEPT for reversibility) and a permanent redirect is
 * created so /post/{retired} 301s to /post/{survivor}.
 *
 * REVERSIBLE: the retired post is only unpublished, never deleted; --undo
 * republishes it and removes the redirect. Nothing about the survivor changes,
 * so edit the survivor's body separately (or with content:scrub-placeholders).
 *
 *   php artisan posts:merge {retired-slug} {survivor-slug} --reason="duplicate of X"
 *   php artisan posts:merge {retired-slug} {survivor-slug} --undo
 */
class PostsMerge extends Command
{
    protected $signature = 'posts:merge
        {retired : Slug of the article to retire (redirect FROM)}
        {survivor : Slug of the article to keep (redirect TO)}
        {--reason=merged duplicate : Note stored on the redirect}
        {--undo : Reverse a previous merge (republish + drop the redirect)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Consolidate a duplicate article into another and 301-redirect the retired URL (reversible).';

    public function handle(): int
    {
        $retiredSlug = $this->argument('retired');
        $survivorSlug = $this->argument('survivor');

        $retired = Post::where('slug', $retiredSlug)->first();
        if (! $retired) {
            $this->error("Retired post not found: {$retiredSlug}");

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            return $this->undo($retired, $survivorSlug);
        }

        $survivor = Post::where('slug', $survivorSlug)->first();
        if (! $survivor) {
            $this->error("Survivor post not found: {$survivorSlug}");

            return self::FAILURE;
        }
        if ($retired->id === $survivor->id) {
            $this->error('Retired and survivor are the same post.');

            return self::FAILURE;
        }

        $this->line("Retire:   #{$retired->id}  {$retired->title}  (status: {$retired->status})");
        $this->line("Keep:     #{$survivor->id}  {$survivor->title}");
        $this->line("Redirect: /post/{$retiredSlug}  ->  /post/{$survivorSlug}  (301)");

        if (! $this->option('force') && ! $this->confirm('Proceed?', true)) {
            return self::SUCCESS;
        }

        // Unpublish the retired article (row kept for reversibility).
        $retired->forceFill(['status' => 'archived'])->saveQuietly();

        PostRedirect::updateOrCreate(
            ['from_slug' => $retiredSlug],
            ['to_slug' => $survivorSlug, 'reason' => (string) $this->option('reason')],
        );

        // Drop the retired URL from the sitemaps right away (else up to 30 min stale).
        \Illuminate\Support\Facades\Cache::forget('sitemap.index');
        \Illuminate\Support\Facades\Cache::forget('sitemap.news');

        $this->info("Merged. /post/{$retiredSlug} now 301s to /post/{$survivorSlug}. Undo with --undo.");

        return self::SUCCESS;
    }

    private function undo(Post $retired, string $survivorSlug): int
    {
        $retired->forceFill(['status' => 'published'])->saveQuietly();
        PostRedirect::where('from_slug', $retired->slug)->delete();

        \Illuminate\Support\Facades\Cache::forget('sitemap.index');
        \Illuminate\Support\Facades\Cache::forget('sitemap.news');

        $this->info("Undone. /post/{$retired->slug} is published again and the redirect is removed.");

        return self::SUCCESS;
    }
}
