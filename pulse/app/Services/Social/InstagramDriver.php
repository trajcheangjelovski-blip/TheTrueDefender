<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Http;

/**
 * Instagram (Business/Creator) via the Graph API — two-step container publish.
 * Instagram REQUIRES an image: text-only posts are not possible. A post with
 * no real featured image is skipped with a clear message.
 */
class InstagramDriver extends AbstractSocialDriver
{
    public function key(): string { return 'instagram'; }

    public function label(): string { return 'Instagram'; }

    public function configFields(): array
    {
        return [
            'ig_user_id' => 'Instagram Business account ID',
            'access_token' => 'Access token',
        ];
    }

    public function send(Post $post, array $config): array
    {
        if ($m = $this->missing($config, ['ig_user_id', 'access_token'])) {
            return $this->fail($m);
        }

        // Instagram cannot post without an image. Our posts use emoji art by
        // default, so this only works once posts carry a real, public image URL.
        if (blank($post->featured_image)) {
            return $this->fail('Instagram requires an image; this post has none (emoji art is not publishable).');
        }

        $imageUrl = url('storage/' . $post->featured_image);
        $caption = trim($post->title . "\n\n" . ($post->excerpt ?? '') . "\n\n" . $this->postUrl($post));

        try {
            // 1) Create media container
            $container = Http::timeout(30)->asForm()
                ->post("https://graph.facebook.com/v21.0/{$config['ig_user_id']}/media", [
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                    'access_token' => $config['access_token'],
                ])
                ->throw()->json();

            $creationId = data_get($container, 'id');
            if (! $creationId) {
                return $this->fail('Instagram: no container id returned');
            }

            // 2) Publish the container
            $res = Http::timeout(30)->asForm()
                ->post("https://graph.facebook.com/v21.0/{$config['ig_user_id']}/media_publish", [
                    'creation_id' => $creationId,
                    'access_token' => $config['access_token'],
                ])
                ->throw()->json();

            $id = (string) data_get($res, 'id');
            return $this->ok($id);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
