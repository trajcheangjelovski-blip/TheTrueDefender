<?php

namespace App\Observers;

use App\Jobs\SendSocialPosts;
use App\Models\Post;
use App\Services\ImageService;

class PostObserver
{
    public function saved(Post $post): void
    {
        // Web push is NOT fired per-post (that would spam subscribers on a
        // high-volume auto-ingest site). Instead the scheduled `push:notify`
        // command sends at most one push per interval, choosing the most
        // important recent story. Social fan-out still fires per publish.
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
