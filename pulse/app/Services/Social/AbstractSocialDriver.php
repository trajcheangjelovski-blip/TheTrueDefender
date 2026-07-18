<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Str;

abstract class AbstractSocialDriver implements SocialDriver
{
    protected function postUrl(Post $post): string
    {
        return route('post.show', $post);
    }

    /** Default share text: headline + excerpt + link. */
    protected function text(Post $post, int $limit = 600): string
    {
        $parts = array_filter([
            $post->title,
            $post->excerpt,
            $this->postUrl($post),
        ]);

        return Str::limit(implode("\n\n", $parts), $limit, preserveWords: true);
    }

    protected function ok(?string $id = null, ?string $url = null): array
    {
        return ['ok' => true, 'id' => $id, 'url' => $url, 'error' => null];
    }

    protected function fail(string $error): array
    {
        return ['ok' => false, 'id' => null, 'url' => null, 'error' => $error];
    }

    protected function missing(array $config, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (blank($config[$k] ?? null)) {
                return "Missing config: {$k}";
            }
        }
        return null;
    }
}
