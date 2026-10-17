<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\BreakingPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Breaking-news fast lane, retry loop. Ingest tries to publish a breaking story
 * the moment it is found; when other outlets haven't covered it yet (or the
 * write-up needs another pass), this re-tries every minute so it goes live as
 * soon as it can clear the bar. Each story is retried at most every 2 minutes,
 * for up to 3 hours after ingest.
 */
class PostsBreaking extends Command
{
    protected $signature = 'posts:breaking {--limit=3 : Max breaking drafts to try this run}';

    protected $description = 'Retry the breaking-news fast lane for queued breaking drafts (publish within minutes).';

    public function handle(BreakingPublisher $fastLane): int
    {
        $posts = Post::where('status', 'draft')
            ->where('is_breaking', true)
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->where('created_at', '>=', now()->subHours(3))
            ->orderByDesc('editorial_score')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($posts as $post) {
            if (! Cache::add("breaking-retry:{$post->id}", 1, 110)) {
                continue; // tried within the last 2 minutes
            }
            $this->line("#{$post->id} " . $fastLane->attempt($post) . '  — ' . Str::limit($post->title, 50));
        }

        return self::SUCCESS;
    }
}
