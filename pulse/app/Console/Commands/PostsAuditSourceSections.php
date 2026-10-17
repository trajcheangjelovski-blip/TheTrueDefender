<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\PublicSourceSections;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Read-only, repeatable audit. Never rewrites an article or research record. */
class PostsAuditSourceSections extends Command
{
    protected $signature = 'posts:audit-source-sections
        {--all : Include drafts and future publications}
        {--snapshot : Back up original article and research fields to private storage}';

    protected $description = 'Audit stored public article bodies for standalone source sections without changing content.';

    public function handle(): int
    {
        $posts = ($this->option('all') ? Post::query() : Post::published())->orderBy('id')->get();
        if ($this->option('snapshot')) {
            $path = 'editorial-audits/source-sections-' . now()->format('Ymd-His') . '-' . Str::uuid() . '.json';
            $json = json_encode([
                'created_at' => now()->toIso8601String(),
                'posts' => $posts->map(fn (Post $p) => $p->getRawOriginal())->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! Storage::disk('local')->put($path, $json)) {
                $this->error('Snapshot failed. No article data changed.');

                return self::FAILURE;
            }
            $this->info('Private snapshot: ' . Storage::disk('local')->path($path));
        }

        $candidates = $posts->filter(fn (Post $p) => PublicSourceSections::hasSection($p->body));
        $withResearch = $posts->filter(fn (Post $p) => count((array) $p->sources) > 0)->count();
        $this->info("Inspected {$posts->count()} articles; {$withResearch} retain internal source records; {$candidates->count()} embedded source-section candidates. No article data changed.");
        foreach ($candidates as $post) {
            $this->warn("Review #{$post->id}: {$post->slug}");
        }

        // A candidate is a review item, not permission to delete surrounding prose.
        return $candidates->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
