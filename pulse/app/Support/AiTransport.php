<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/** Explicit provider selection. A saved paid key never enables a cloud fallback. */
final class AiTransport
{
    public static function local(): bool
    {
        $provider = (string) config('services.ai.provider', 'ollama');
        if (! in_array($provider, ['ollama', 'openai'], true)) {
            throw new \RuntimeException('Unknown AI provider; refusing fallback');
        }

        return $provider === 'ollama';
    }

    public static function key(): string
    {
        return self::local() ? (string) config('services.ai.token', '')
            : (string) Setting::get('openai_key', config('services.openai.key'));
    }

    public static function model(): string
    {
        return (string) config('services.ai.model', 'qwen3.5:9b');
    }

    public static function http(): AiRequest
    {
        return new AiRequest;
    }

    public static function gateway(): string
    {
        $url = rtrim((string) config('services.ai.url', 'http://127.0.0.1:8091'), '/');
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)
            || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)) {
            throw new \RuntimeException('Invalid local AI gateway URL');
        }
        // Deployment must use a private literal address or a Docker service name.
        $ip = trim($host, '[]');
        $tailnet = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && (ip2long($ip) & ip2long('255.192.0.0')) === ip2long('100.64.0.0');
        if (filter_var($ip, FILTER_VALIDATE_IP) && ! $tailnet
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \RuntimeException('Public AI gateway addresses are forbidden');
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP) && str_contains($host, '.')) {
            throw new \RuntimeException('Use a private IP or Docker service name for the gateway');
        }

        return $url;
    }

    public static function health(): array
    {
        if (! self::local()) {
            return ['provider' => 'openai', 'model' => AiModels::writer()];
        }
        try {
            return Http::withToken(self::key())->timeout(3)->withoutRedirecting()
                ->get(self::gateway().'/health')->throw()->json();
        } catch (\Throwable) {
            return ['provider' => 'ollama', 'model' => self::model(), 'worker' => null, 'error' => 'Gateway unavailable; work remains queued'];
        }
    }
}
