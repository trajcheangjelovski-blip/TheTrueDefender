<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use App\Services\Social\SocialManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSocialPosts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Post $post) {}

    public function handle(SocialManager $manager): void
    {
        $post = $this->post->fresh();

        if (! $post || $post->status !== 'published' || $post->social_posted_at !== null) {
            return;
        }

        // Guard: only attempt the fan-out once per post.
        $post->forceFill(['social_posted_at' => now()])->saveQuietly();

        foreach (SocialChannel::where('is_active', true)->get() as $channel) {
            // Don't double-post to the same channel.
            if (SocialPost::where('post_id', $post->id)->where('social_channel_id', $channel->id)->exists()) {
                continue;
            }

            $driver = $manager->resolve($channel->driver);
            if (! $driver) {
                continue;
            }

            $result = $driver->send($post, $channel->config ?? []);

            SocialPost::create([
                'post_id' => $post->id,
                'social_channel_id' => $channel->id,
                'status' => $result['ok'] ? 'sent' : 'failed',
                'external_id' => $result['id'] ?? null,
                'external_url' => $result['url'] ?? null,
                'error' => $result['ok'] ? null : ($result['error'] ?? 'unknown error'),
            ]);
        }
    }
}
