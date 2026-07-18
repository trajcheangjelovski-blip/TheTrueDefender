<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNewPostNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Post $post) {}

    public function handle(PushSender $push): void
    {
        $post = $this->post->fresh();

        // Guard: only notify once, only for published posts.
        if (! $post || $post->status !== 'published' || $post->push_notified_at !== null) {
            return;
        }

        $post->forceFill(['push_notified_at' => now()])->saveQuietly();

        $push->sendToAll([
            'title' => $post->category?->name
                ? $post->category->name . ': ' . \Illuminate\Support\Str::limit($post->title, 60)
                : \Illuminate\Support\Str::limit($post->title, 70),
            'body' => \Illuminate\Support\Str::limit($post->excerpt ?? '', 120),
            'url' => route('post.show', $post),
            'icon' => $post->image_icon ?? '📰',
        ]);
    }
}
