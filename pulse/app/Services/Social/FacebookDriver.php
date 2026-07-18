<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Page — posts a link to a Page feed via the Graph API.
 * Requires a Page access token with pages_manage_posts (Meta app review).
 */
class FacebookDriver extends AbstractSocialDriver
{
    public function key(): string { return 'facebook'; }

    public function label(): string { return 'Facebook Page'; }

    public function configFields(): array
    {
        return [
            'page_id' => 'Facebook Page ID',
            'access_token' => 'Page access token',
        ];
    }

    public function send(Post $post, array $config): array
    {
        if ($m = $this->missing($config, ['page_id', 'access_token'])) {
            return $this->fail($m);
        }

        try {
            $res = Http::timeout(30)
                ->asForm()
                ->post("https://graph.facebook.com/v21.0/{$config['page_id']}/feed", [
                    'message' => trim($post->title . "\n\n" . ($post->excerpt ?? '')),
                    'link' => $this->postUrl($post),
                    'access_token' => $config['access_token'],
                ])
                ->throw()
                ->json();

            $id = (string) data_get($res, 'id');
            return $this->ok($id, $id ? "https://facebook.com/{$id}" : null);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
