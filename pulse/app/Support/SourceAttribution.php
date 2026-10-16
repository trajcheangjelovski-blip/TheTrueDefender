<?php

namespace App\Support;

class SourceAttribution
{
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) ? $url : null;
    }

    /** Accept legacy {name,url} records and the editor's richer source format. */
    public static function normalize(array $sources, ?string $legacyName = null, ?string $legacyUrl = null): array
    {
        if ($legacyUrl) {
            $sources[] = ['publisher' => $legacyName, 'url' => $legacyUrl];
        }
        $result = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! ($url = self::safeUrl($source['url'] ?? null))) {
                continue;
            }
            $key = rtrim($url, '/');
            if (isset($result[$key])) {
                continue;
            }
            $publisher = trim((string) ($source['publisher'] ?? $source['name'] ?? ''));
            $result[$key] = [
                'title' => trim((string) ($source['title'] ?? '')) ?: ($publisher ?: parse_url($url, PHP_URL_HOST)),
                'publisher' => $publisher,
                'url' => $url,
                'note' => trim((string) ($source['note'] ?? '')),
            ];
        }
        return array_values($result);
    }
}
