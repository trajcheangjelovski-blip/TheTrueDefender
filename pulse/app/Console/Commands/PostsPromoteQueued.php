<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PostsPromoteQueued extends Command
{
    protected $signature = 'posts:promote-queued
        {--per-run=1 : Max stories to promote on this run (pacing)}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Publish the highest-scoring QUEUED stories into the day\'s remaining slots (topic-priority, best-first), waiting when nothing clears the bar. Breaking news is published immediately at ingest and is not handled here.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $perRun = max(1, (int) $this->option('per-run'));

        $cap = (int) Setting::get('daily_publish_cap', 0);
        $floor = (int) Setting::get('promote_score_floor', 60);      // quality bar to auto-promote
        $minWords = (int) Setting::get('min_publish_words', 500);    // never promote a thin one
        $expireHours = (float) Setting::get('queue_expire_hours', 18); // stale news drops out of the queue

        // ── 1. Expire stale holds ──────────────────────────────────────────────
        // A queued story that never won a slot within the window is no longer fresh
        // news. Drop it from the queue (clear the marker) so it ages out via the
        // normal stale-draft cleanup instead of being published late.
        $expired = 0;
        if ($expireHours > 0) {
            $stale = Post::where('status', 'draft')->whereNotNull('queued_at')
                ->where('queued_at', '<', now()->subHours($expireHours))->get();
            $expired = $stale->count();
            if (! $dry) {
                foreach ($stale as $p) {
                    $p->forceFill(['queued_at' => null])->saveQuietly();
                }
            }
        }

        // ── 2. How many slots are left today? ──────────────────────────────────
        $publishedToday = Post::where('status', 'published')->whereNotNull('source_name')
            ->whereDate('published_at', today())->count();
        $remaining = $cap <= 0 ? PHP_INT_MAX : max(0, $cap - $publishedToday);

        if ($remaining <= 0) {
            $this->info("Daily cap reached ({$publishedToday}/{$cap}). Expired {$expired} stale. Nothing promoted.");
            return self::SUCCESS;
        }

        // ── 3. Promote the best queued stories, best score first ───────────────
        // Fetch a small ranked pool, then drop any that are (still) thin, then take
        // up to this run's pacing limit and the remaining daily slots.
        $take = min($remaining, $perRun);
        $pool = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->where('editorial_score', '>=', $floor)          // below the bar → keep waiting
            ->orderByDesc('editorial_score')
            ->orderBy('queued_at')                            // tie-break: oldest first
            ->limit($take + 5)
            ->get();

        $promoted = 0;
        foreach ($pool as $post) {
            if ($promoted >= $take) {
                break;
            }
            if (str_word_count(strip_tags((string) $post->body)) < $minWords) {
                continue; // safety: never promote a thin story
            }

            if ($dry) {
                $this->line("  would promote #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
                $promoted++;
                continue;
            }

            // Publish it. A normal save fires PostObserver (social fan-out); web push
            // is handled separately by the throttled push:notify job.
            $post->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'queued_at' => null,
            ])->save();

            $this->info("  promoted #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
            $promoted++;
        }

        $waiting = Post::where('status', 'draft')->whereNotNull('queued_at')->count();
        $this->info(($dry ? '[DRY] ' : '') . "Promoted {$promoted} (cap {$publishedToday}→" . ($publishedToday + $promoted) . "/{$cap}) · expired {$expired} stale · {$waiting} still queued.");

        return self::SUCCESS;
    }
}
