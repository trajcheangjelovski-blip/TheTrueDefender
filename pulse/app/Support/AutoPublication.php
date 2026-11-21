<?php

namespace App\Support;

use App\Models\IngestedItem;
use App\Models\Post;

final class AutoPublication
{
    public static function allowed(Post $post): bool
    {
        // Read the existing feed Auto Publish switch at the final transition, not just at discovery.
        $item = IngestedItem::with('source')->where('post_id', $post->id)->first();

        return $item?->source?->is_active && $item->source->auto_publish;
    }
}
