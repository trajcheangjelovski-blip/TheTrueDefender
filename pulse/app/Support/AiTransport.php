<?php

namespace App\Support;

use App\Models\Setting;

/**
 * One switch for hosted OpenAI versus Ollama's OpenAI-compatible endpoint.
 * Off by default. Never expose the local inference port publicly.
 */
final class AiTransport
{
    public static function local(): bool
    {
        return (bool) config('services.ollama.enabled', false);
    }

    public static function key(): string
    {
        return self::local() ? 'ollama-local' : (string) Setting::get('openai_key', config('services.openai.key'));
    }

    public static function model(): string
    {
        return self::local()
            ? (string) config('services.ollama.model', 'qwen3:1.7b')
            : (string) Setting::get('openai_model', config('services.openai.model'));
    }

    public static function endpoint(string $route): string
    {
        $base = self::local()
            ? (string) config('services.ollama.base_url', 'http://ollama:11434/v1')
            : 'https://api.openai.com/v1';

        return rtrim($base, '/') . '/' . ltrim($route, '/');
    }
}
