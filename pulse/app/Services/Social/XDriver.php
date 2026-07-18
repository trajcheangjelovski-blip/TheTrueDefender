<?php

namespace App\Services\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * X (Twitter) — posts a tweet via API v2 (POST /2/tweets) using OAuth 1.0a
 * user-context signing. Requires a paid X API tier with write access.
 */
class XDriver extends AbstractSocialDriver
{
    public function key(): string { return 'x'; }

    public function label(): string { return 'X (Twitter)'; }

    public function configFields(): array
    {
        return [
            'api_key' => 'API key (consumer key)',
            'api_secret' => 'API secret (consumer secret)',
            'access_token' => 'Access token',
            'access_secret' => 'Access token secret',
        ];
    }

    public function send(Post $post, array $config): array
    {
        if ($m = $this->missing($config, ['api_key', 'api_secret', 'access_token', 'access_secret'])) {
            return $this->fail($m);
        }

        $url = 'https://api.twitter.com/2/tweets';
        $tweet = Str::limit($post->title, 250, '') . "\n" . $this->postUrl($post);

        try {
            $auth = $this->oauthHeader('POST', $url, $config);
            $res = Http::timeout(30)
                ->withHeaders(['Authorization' => $auth])
                ->asJson()
                ->post($url, ['text' => $tweet])
                ->throw()
                ->json();

            $id = (string) data_get($res, 'data.id');
            return $this->ok($id, $id ? "https://x.com/i/web/status/{$id}" : null);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** Build an OAuth 1.0a Authorization header (HMAC-SHA1). */
    private function oauthHeader(string $method, string $url, array $config): string
    {
        $oauth = [
            'oauth_consumer_key' => $config['api_key'],
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $config['access_token'],
            'oauth_version' => '1.0',
        ];

        ksort($oauth);
        $paramString = http_build_query($oauth, '', '&', PHP_QUERY_RFC3986);
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $signingKey = rawurlencode($config['api_secret']) . '&' . rawurlencode($config['access_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $signingKey, true));

        $header = 'OAuth ';
        $pairs = [];
        foreach ($oauth as $k => $v) {
            $pairs[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        }
        return $header . implode(', ', $pairs);
    }
}
