<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use DOMDocument;
use DOMXPath;

class ArticleLinks
{
    /** Post links require explicit editorial selection, never a keyword match. No HTTP calls. */
    public static function forDisplay(string $html, array $relatedIds = []): string
    {
        if (! str_contains(strtolower($html), '<a')) {
            return $html;
        }
        $approved = Post::published()->whereIn('id', $relatedIds)->pluck('slug')->all();
        $ownHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="article-link-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (iterator_to_array((new DOMXPath($doc))->query('//a')) as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $parts = parse_url($href);
            $keep = $parts !== false && ! isset($parts['user']) && ! isset($parts['pass'])
                && (! isset($parts['scheme']) || in_array(strtolower($parts['scheme']), ['http', 'https'], true));
            $internal = ! isset($parts['host']) || strtolower($parts['host']) === $ownHost;
            $path = '/' . ltrim(rtrim($parts['path'] ?? '', '/'), '/');
            if ($keep && $internal && preg_match('#^/post/([^/]+)$#', $path, $match)) {
                $keep = in_array(rawurldecode($match[1]), $approved, true);
            } elseif ($keep && $internal && preg_match('#^/category/([^/]+)$#', $path, $match)) {
                $keep = Category::where('slug', rawurldecode($match[1]))->where('is_active', true)
                    ->whereHas('posts', fn ($q) => $q->published())->exists();
            }
            if ($keep && $internal && preg_match('#^/topic/([^/]+)$#', $path, $match)) {
                $keep = Tag::where('slug', rawurldecode($match[1]))->where('is_active', true)
                    ->whereHas('posts', fn ($q) => $q->published())->exists();
            }
            if (! $keep) {
                while ($anchor->firstChild) {
                    $anchor->parentNode->insertBefore($anchor->firstChild, $anchor);
                }
                $anchor->parentNode->removeChild($anchor);
            }
        }
        $root = $doc->getElementById('article-link-root');
        return $root ? implode('', array_map(fn ($node) => $doc->saveHTML($node), iterator_to_array($root->childNodes))) : $html;
    }
}
