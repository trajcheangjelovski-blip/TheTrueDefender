<?php

namespace App\Support;

/**
 * Strips AI/SEO meta-commentary that should never reach readers — e.g.
 * "the focus keyword is…", "in this article we will…", "according to the
 * provided information…". Removes the offending sentence (or empty paragraph).
 */
class ArticleSanitizer
{
    /** Case-insensitive phrases; any sentence containing one is dropped. */
    private const BAD = [
        'focus keyword',
        'target keyword',
        'keyword phrase',
        'phrase people will search',
        'people will search for',
        'search for here is',
        'search intent',
        'meta description',
        'meta title',
        'meta-description',
        'in this article, we will',
        'in this article we will',
        'in this piece we will',
        'in this post we will',
        'according to the provided',
        'based on the provided',
        'the provided information',
        'the provided article',
        'the provided text',
        'the source material',
        'as an ai',
        'language model',
        'seo purposes',
        'seo-friendly',
        'for seo',
        'optimized for search',
        'as requested',
    ];

    /** Clean HTML body: drop offending sentences, then any emptied paragraphs. */
    public static function clean(?string $html): string
    {
        if (blank($html)) {
            return (string) $html;
        }

        // Process paragraph by paragraph so we can drop empties cleanly.
        $out = preg_replace_callback('/<p\b[^>]*>(.*?)<\/p>/is', function ($m) {
            $inner = self::cleanText($m[1]);
            return trim(strip_tags($inner)) === '' ? '' : '<p>' . $inner . '</p>';
        }, $html);

        // If there were no <p> wrappers, clean the whole thing as text.
        if (! str_contains(strtolower($html), '<p')) {
            $out = self::cleanText($html);
        }

        return trim($out);
    }

    /** Clean a plain string (excerpt / social caption): drop offending sentences. */
    public static function cleanText(?string $text): string
    {
        if (blank($text)) {
            return (string) $text;
        }

        // Split into sentences, keeping their trailing punctuation/space.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $kept = array_filter($sentences, fn ($s) => ! self::isBad($s));

        return trim(implode(' ', $kept));
    }

    private static function isBad(string $sentence): bool
    {
        $lower = mb_strtolower($sentence);
        foreach (self::BAD as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
