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
                ->withHeaders([
                    // A realistic browser UA + headers: many news sites serve a
                    // stripped-down page (or block) unknown bots, which is a big
                    // reason full-text extraction was failing.
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
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
        // 1. Best source: the publisher's own full article text, embedded in
        //    JSON-LD structured data (Article/NewsArticle → articleBody). This is
        //    clean, complete, and boilerplate-free — most news sites include it.
        if ($body = $this->jsonLdArticleBody($html)) {
            return $body;
        }

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

    /**
     * Pull the full article text from the page's JSON-LD structured data
     * (Article/NewsArticle → articleBody). Handles single objects, arrays of
     * objects, and @graph containers. Returns null if none carries a real body.
     */
    private function jsonLdArticleBody(string $html): ?string
    {
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $json) {
            // Some pages HTML-escape the JSON or wrap it in CDATA; clean lightly.
            $json = trim(preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $json) ?? $json);
            $data = json_decode($json, true);
            if (! is_array($data)) {
                continue;
            }

            $body = $this->findArticleBody($data);
            if ($body !== null) {
                $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5));
                // Normalize whitespace but keep paragraph breaks.
                $body = preg_replace("/[ \t]+/", ' ', $body);
                $body = preg_replace("/\n{3,}/", "\n\n", $body);
                if (Str::length($body) >= self::MIN_CHARS) {
                    return Str::limit($body, self::MAX_CHARS, '');
                }
            }
        }

        return null;
    }

    /** Recursively locate an "articleBody" string anywhere in decoded JSON-LD. */
    private function findArticleBody($node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        if (isset($node['articleBody']) && is_string($node['articleBody'])
            && Str::length(trim($node['articleBody'])) >= 200) {
            return $node['articleBody'];
        }

        foreach ($node as $value) {
            if (is_array($value) && ($found = $this->findArticleBody($value)) !== null) {
                return $found;
            }
        }

        return null;
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
