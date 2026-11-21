<?php

namespace App\Support;

use App\Models\Post;
use App\Services\ImageService;
use App\Services\SeoBooster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class Publisher
{
    public static function publish(Post $post, ImageService $images, SeoBooster $booster): void
    {
        $post->refresh(); // include database defaults in the checked publication snapshot
        if (! AutoPublication::allowed($post) || PublishGate::failures($post) || FinalCheck::failures($post)) {
            return;
        }
        $fingerprint = fn (Post $row) => hash('sha256', json_encode($row->only(['title', 'body', 'fact_check', 'research_package', 'sources', 'event_key', 'is_breaking']), JSON_THROW_ON_ERROR));
        $verifiedBody = $fingerprint($post);
        Cache::lock('publish:event:'.hash('sha256', (string) ($post->event_key ?: $post->id)), 60)->block(5,
            function () use ($post, $verifiedBody, $fingerprint) {
                DB::transaction(function () use ($post, $verifiedBody, $fingerprint) {
                    $fresh = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
                    if ($fresh->status !== 'draft' || ! AutoPublication::allowed($fresh)
                        || $fingerprint($fresh) !== $verifiedBody || PublishGate::failures($fresh)) {
                        return;
                    }
                    if ($fresh->event_key && Post::where('event_key', $fresh->event_key)->where('status', 'published')->whereKeyNot($fresh->id)->exists()) {
                        $fresh->forceFill(['review_status' => 'rejected', 'queued_at' => null])->save();

                        return;
                    }
                    // A category icon is the lawful fallback; no speculative image or paid call.
                    $fresh->forceFill(['status' => 'published', 'published_at' => $fresh->published_at ?? now(), 'queued_at' => null])->save();
                });
            });
    }
}
