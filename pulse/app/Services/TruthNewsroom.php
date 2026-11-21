<?php

namespace App\Services;

use App\Models\NewsroomOutbox;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Str;

final class TruthNewsroom
{
    public static function profiles(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('truth_profiles', '')))));
    }

    public static function enqueue(Post $post): void
    {
        if ($post->status !== 'published' || ! in_array($post->truth_profile, self::profiles(), true)) {
            return;
        }
        $key = hash('sha256', config('app.url').':published:'.$post->id.':'.$post->truth_profile);
        NewsroomOutbox::firstOrCreate(['post_id' => $post->id, 'profile' => $post->truth_profile], [
            'idempotency_key' => $key, 'status' => 'pending',
            'payload' => ['event_id' => $key, 'article_id' => (string) $post->id, 'profile' => $post->truth_profile,
                'article_url' => route('post.show', $post->slug), 'title' => $post->title,
                'caption' => Str::limit($post->title, 300), 'published_at' => $post->published_at?->toIso8601String()],
        ]);
    }
}
