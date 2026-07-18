<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fetches the full text of a source news article so the AI can write a
 * complete original story instead of a thin summary from the 1-2 sentence
 * RSS snippet. Extraction is heuristic: prefer <article>, then <main>,
 * then the densest cluster of <p> tags in the body.
 *
 * The extracted text is ONLY used as AI input (rewritten in our own words
 * with attribution) — it is never stored or published verbatim.
 */
class ArticleFetcher
{
    /** Fetched text shorter than this is treated as a failed extraction. */
    private const MIN_CHARS = 400;

    /** Cap what we send to the model. */
    private const MAX_CHARS = 9000;

    /**
     * Fetch both the article text AND its high-resolution social image
     * (og:image — far larger than the tiny RSS thumbnail).
     *
     * @return array{text: ?string, image: ?string}
     */
    public function extract(?string $url): array
    {
        $html = $this->download($url);

        return [
            'text' => $html ? $this->textFromHtml($html) : null,
            'image' => $html ? $this->ogImage($html) : null,
        ];
    }

    public function fullText(?string $url): ?string
    {
        $html = $this->download($url);

        return $html ? $this->textFromHtml($html) : null;
    }

    private function download(?string $url): ?string
    {
        if (blank($url) || ! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        try {
            return Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TheTrueDefenderBot/1.0)'])
                ->get($url)
                ->throw()
                ->body();
        } catch (\Throwable $e) {
            Log::info("Article fetch failed for {$url}: " . $e->getMessage());

            return null;
        }
    }

    /** The page's high-res social-share image (og:image / twitter:image). */
    private function ogImage(string $html): ?string
    {
        foreach (['og:image', 'twitter:image'] as $prop) {
            if (preg_match(
                '/<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i',
                $html, $m,
            ) || preg_match(
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\']/i',
                $html, $m,
            )) {
                $src = html_entity_decode(trim($m[1]));
                if (Str::startsWith($src, ['http://', 'https://'])) {
                    return $src;
                }
            }
        }

        return null;
    }

    private function textFromHtml(string $html): ?string
    {

        // Drop non-content blocks wholesale before extracting.
        $html = preg_replace('/<(script|style|noscript|svg|form|iframe|nav|header|footer|aside|figure|figcaption|button)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? '';
        $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? '';

        // Prefer semantic containers, largest first.
        foreach (['article', 'main'] as $tag) {
            if (preg_match_all("/<{$tag}\b[^>]*>(.*?)<\/{$tag}>/is", $html, $m) && $m[1]) {
                usort($m[1], fn ($a, $b) => strlen($b) <=> strlen($a));
                if ($text = $this->paragraphs($m[1][0])) {
                    return $text;
                }
            }
        }

        // Fallback: all paragraphs in the page body.
        return $this->paragraphs($html);
    }

    /** Pull paragraph text out of an HTML fragment; null if too thin. */
    private function paragraphs(string $fragment): ?string
    {
        if (! preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $fragment, $m)) {
            return null;
        }

        $paragraphs = collect($m[1])
            ->map(fn ($p) => trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5)))
            // Skip boilerplate: cookie notices, share prompts, bylines, one-word scraps.
            ->filter(fn ($p) => Str::length($p) >= 60)
            ->values();

        $text = $paragraphs->implode("\n\n");

        if (Str::length($text) < self::MIN_CHARS) {
            return null;
        }

        return Str::limit($text, self::MAX_CHARS, '');
    }
}
