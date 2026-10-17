<?php

namespace App\Support;

use App\Models\Post;
use App\Services\ImageService;
use App\Services\SeoBooster;

/**
 * Takes a draft live: featured image (generated now, so we only pay for images on
 * stories that actually publish), status/published_at, then search metadata.
 * Shared by posts:promote-queued and the breaking fast lane (BreakingPublisher).
 * Cap, pacing and PublishGate checks are the caller's job.
 */
class Publisher
{
    public static function publish(Post $post, ImageService $images, SeoBooster $booster): void
    {
        // AI-first image, matching the sources' ai_image preference; a blip just
        // leaves the category icon (posts:backfill-images heals it later).
        $imageId = $post->featured_image;
        if (blank($imageId)) {
            $prompt = 'Editorial news illustration for a ' . ($post->category?->name ?? 'news')
                . " story titled: {$post->title}. Photorealistic, tasteful, no text, no logos, no watermarks.";
            try {
                $imageId = $images->generate($prompt) ?: null;
            } catch (\Throwable) {
                $imageId = null;
            }
        }

        // A normal save fires PostObserver (social fan-out); web push is handled
        // separately by the throttled push:notify job.
        $post->forceFill([
            'featured_image' => $imageId,
            'status' => 'published',
            'published_at' => now(),
            'queued_at' => null,
        ])->save();

        // Search metadata (meta title/description/keyword — never the body).
        try {
            if ((int) ($post->seo_score ?? 0) < 80) {
                $booster->boost($post);
            }
        } catch (\Throwable) {
            // non-fatal — the post is still published
        }
    }
}
