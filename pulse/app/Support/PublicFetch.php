<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Fetch source documents without permitting redirects/DNS rebinding into private services. */
final class PublicFetch
{
    public static function get(string $url, int $redirects = 4, int $maxBytes = 6_000_000): Response
    {
        for ($hop = 0; $hop <= $redirects; $hop++) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
            if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! $host
                || isset($parts['user']) || isset($parts['pass']) || ! in_array($port, [80, 443], true)) {
                throw new \RuntimeException('Invalid public source URL');
            }
            $addresses = app(PublicDns::class)->addresses($host);
            if (! $addresses) {
                throw new \RuntimeException('Source DNS unavailable');
            }
            foreach ($addresses as $ip) {
                // IPv4 only for source downloads; no private, reserved or shared tailnet addresses.
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    || (ip2long($ip) & ip2long('255.192.0.0')) === ip2long('100.64.0.0')) {
                    throw new \RuntimeException('Private source address rejected');
                }
            }
            $response = Http::connectTimeout(5)->timeout(25)->withoutRedirecting()
                ->withUserAgent('TheTrueDefender/1.0 (source research)')
                ->withOptions(['proxy' => '', 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses[0]}"], CURLOPT_PROXY => ''],
                    'progress' => function ($total, $downloaded) use ($maxBytes) {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new \RuntimeException('Source exceeds download limit');
                        }
                    }])->get($url);
            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! $location) {
                    throw new \RuntimeException('Empty source redirect');
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if (strlen($response->body()) > $maxBytes) {
                throw new \RuntimeException('Source exceeds download limit');
            }

            return $response->throw();
        }
        throw new \RuntimeException('Too many source redirects');
    }
}
