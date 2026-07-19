<?php

namespace App\Console\Commands;

use App\Jobs\SendNewPostNotification;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('push:notify {--force : Ignore the interval and send now if a post qualifies}')]
#[Description('Send at most one web push per interval, choosing the most important recent unpushed post')]
class PushNotify extends Command
{
    public function handle(): int
    {
        $intervalHours = (float) Setting::get('push_interval_hours', 2);

        // Rate-limit: skip if we pushed within the interval.
        $last = Setting::get('last_push_at');
        if (! $this->option('force') && $last
            && Carbon::parse($last)->diffInMinutes(now()) < $intervalHours * 60) {
            $this->info('Within push interval — nothing sent.');

            return self::SUCCESS;
        }

        // Candidates: published, not yet pushed, fresh (last 24h).
        $fresh = fn () => Post::published()
            ->whereNull('push_notified_at')
            ->where('published_at', '>=', now()->subDay());

        // Priority: Breaking → Top Story → Trending → newest.
        $post = (clone $fresh())->breakingActive()->latest('published_at')->first()
            ?? (clone $fresh())->where('is_featured', true)->latest('published_at')->first()
            ?? (clone $fresh())->trendingActive()->latest('published_at')->first()
            ?? (clone $fresh())->latest('published_at')->first();

        if (! $post) {
            $this->info('No fresh unpushed post to notify about.');

            return self::SUCCESS;
        }

        // SendNewPostNotification marks push_notified_at + sends to all subscribers.
        SendNewPostNotification::dispatch($post);
        Setting::put('last_push_at', now()->toDateTimeString());

        $this->info("Queued push: {$post->title}");

        return self::SUCCESS;
    }
}
