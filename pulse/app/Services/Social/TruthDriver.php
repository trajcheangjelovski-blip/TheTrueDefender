<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Http;

/**
 * Truth Social — no official public API. Truth Social runs a Mastodon-compatible
 * backend, so we post via the Mastodon statuses endpoint with a bearer token.
 * Best-effort: unofficial and may break if their API changes or blocks it.
 */
class TruthDriver extends AbstractSocialDriver
{
    public function key(): string { return 'truth'; }

    public function label(): string { return 'Truth Social (best-effort)'; }

    public function configFields(): array
    {
        return [
            'base_url' => 'Base URL (default https://truthsocial.com)',
            'access_token' => 'Access token (bearer)',
        ];
    }

    public function send(Post $post, array $config): array
    {
        if ($m = $this->missing($config, ['access_token'])) {
            return $this->fail($m);
        }

        $base = rtrim($config['base_url'] ?? 'https://truthsocial.com', '/');

        try {
            $res = Http::timeout(30)
                ->withToken($config['access_token'])
                ->asForm()
                ->post("{$base}/api/v1/statuses", [
                    'status' => $this->text($post, 500),
                ])
                ->throw()
                ->json();

            $id = (string) data_get($res, 'id');
            return $this->ok($id, data_get($res, 'url'));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
