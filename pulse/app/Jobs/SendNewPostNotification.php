<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SendNewPostNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  bool  $force  Re-send even if this post was already notified (manual push).
     */
    public function __construct(public Post $post, public bool $force = false) {}

    public function handle(PushSender $push): void
    {
        $post = $this->post->fresh();

        // Guard: only for published posts; skip if already notified unless forced.
        if (! $post || $post->status !== 'published') {
            return;
        }
        if (! $this->force && $post->push_notified_at !== null) {
            return;
        }

        $post->forceFill(['push_notified_at' => now()])->saveQuietly();

        $push->sendToAll(self::payloadFor($post));
    }

    /**
     * The web-push payload for a post — shared by the automatic and manual senders
     * so both look identical on the device.
     *
     * @return array{title:string,body:string,url:string,icon:string}
     */
    public static function payloadFor(Post $post): array
    {
        return [
            'title' => $post->category?->name
                ? $post->category->name . ': ' . Str::limit($post->title, 60)
                : Str::limit($post->title, 70),
            'body' => Str::limit($post->excerpt ?? '', 120),
            'url' => route('post.show', $post),
            'icon' => $post->image_icon ?? '📰',
        ];
    }
}
