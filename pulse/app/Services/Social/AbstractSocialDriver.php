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

    /** Default share text: a short AI caption first, then the link on its own line. */
    protected function text(Post $post, int $limit = 600): string
    {
        $url = $this->postUrl($post);
        $caption = app(\App\Services\SocialCaptionService::class)->for($post);

        // Reserve room for the link (+ the blank line) so the URL is never truncated.
        $room = max(40, $limit - mb_strlen($url) - 5);
        $caption = Str::limit(trim($caption), $room, preserveWords: true);

        return $caption . "\n\n" . $url;
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
