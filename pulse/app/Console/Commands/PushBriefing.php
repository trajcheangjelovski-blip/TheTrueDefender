<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Services\PushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PushBriefing extends Command
{
    protected $signature = 'push:briefing {--force : Send even if one went out today}';

    protected $description = 'Send subscribers a once-daily "morning headlines" push featuring the top story.';

    public function handle(PushSender $push): int
    {
        if (! $this->option('force') && Setting::get('last_briefing_at') === now()->toDateString()) {
            $this->info('Morning briefing already sent today.');
            return self::SUCCESS;
        }

        if (blank(config('webpush.vapid.public_key')) || PushSubscription::count() === 0) {
            $this->info('Push not configured or no subscribers.');
            return self::SUCCESS;
        }

        $top = Post::published()->where('published_at', '>=', now()->subDay())
            ->orderByDesc('is_featured')->orderByDesc('is_trending')->orderByDesc('views')
            ->latest('published_at')->first();

        if (! $top) {
            $this->info('No recent story for the briefing.');
            return self::SUCCESS;
        }

        $sent = $push->sendToAll([
            'title' => '☀️ Your morning headlines',
            'body' => Str::limit($top->title, 110),
            'url' => route('post.show', $top),
            'icon' => asset('icon-192.png'),
            'badge' => asset('icon-badge.png'),
            'image' => $top->imageUrl('hero'),
        ]);

        Setting::put('last_briefing_at', now()->toDateString());
        $this->info("Morning briefing sent to {$sent} device(s): {$top->title}");

        return self::SUCCESS;
    }
}
