<?php

namespace App\Observers;

use App\Jobs\SendNewPostNotification;
use App\Jobs\SendSocialPosts;
use App\Models\Post;
use App\Services\ImageService;

class PostObserver
{
    public function saved(Post $post): void
    {
        // When a post becomes published, fire web push and social fan-out once each.
        if ($post->status === 'published' && $post->push_notified_at === null) {
            SendNewPostNotification::dispatch($post);
        }

        if ($post->status === 'published' && $post->social_posted_at === null) {
            SendSocialPosts::dispatch($post);
        }

        // Make sure every featured image has its per-placement size variants
        // (hero/card/thumb) — covers admin uploads as well as AI-ingested images.
        if ($post->featured_image) {
            $hero = preg_replace('/\.[^.]+$/', '', $post->featured_image) . '-hero.jpg';
            if (! file_exists(storage_path('app/public/' . $hero))) {
                app(ImageService::class)->makeVariants($post->featured_image);
            }
        }
    }
}
