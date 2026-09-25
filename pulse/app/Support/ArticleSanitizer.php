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
        // AI tells that reveal it worked from a snippet/summary, not real reporting.
        'summary provided',
        'provided summary',
        'in the summary',
        'based on the summary',
        'in the snippet',
        'the snippet provided',
        'no further details were provided',
        'no additional details were provided',
        'details were not provided',
        'is not specified in the',
        'was not specified in the',
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

        // Remove leftover template/placeholder tokens BEFORE the sentence pass so
        // an emptied paragraph is dropped cleanly below (e.g. a bare "<p>[[LINK]]</p>").
        $html = self::stripPlaceholders($html);

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

    /**
     * Remove leftover template/placeholder tokens the AI sometimes emits where it
     * intended a link or an insert but produced only a marker — e.g. "[[LINK]]",
     * "[LINK]", "{{source}}", "[citation needed]", "[insert quote]". These must
     * never reach readers (a literal "[[LINK]]" shipped on a live article once).
     *
     * Deliberately narrow so legitimate bracketed editorial marks survive:
     *  - "[sic]", party labels like "(R)" / "[D]", and inline citations like "[1]"
     *    are NOT matched.
     */
    /** Placeholder-token patterns. Narrow: real editorial marks ([sic], [1], (R)) are NOT matched. */
    private const PLACEHOLDER_PATTERNS = [
        // Any double-bracketed or double-braced token is always a placeholder.
        '/\[\[[^\]]*\]\]/u',
        '/\{\{[^}]*\}\}/u',
        // Single-bracket/brace placeholders limited to known marker keywords so
        // real editorial brackets ("[sic]", "[1]") are left untouched.
        '/[\[\{]\s*(?:link|url|href|source|src|citation needed|image|img|photo|video|quote|insert[^\]\}]*|placeholder|tbd|todo|xx+)\s*[\]\}]/iu',
    ];

    public static function stripPlaceholders(?string $text): string
    {
        // No-op unless a real placeholder token is present — so the cosmetic
        // whitespace tidy below never fires on ordinary text (which would make
        // hasPlaceholder() and this method report false changes on clean posts).
        if (blank($text) || ! self::hasPlaceholder($text)) {
            return (string) $text;
        }

        $out = preg_replace(self::PLACEHOLDER_PATTERNS, '', $text);

        // Tidy the whitespace/punctuation the removal can leave behind.
        $out = preg_replace('/[ \t]{2,}/', ' ', (string) $out);   // collapsed double spaces
        $out = preg_replace('/\s+([.,;:!?])/u', '$1', $out);        // space before punctuation
        $out = preg_replace('/([([]) +/u', '$1', $out);            // space after an opening bracket

        return $out;
    }

    /** Clean a plain string (excerpt / social caption): drop offending sentences. */
    public static function cleanText(?string $text): string
    {
        if (blank($text)) {
            return (string) $text;
        }

        $text = self::stripPlaceholders($text);

        // Split into sentences, keeping their trailing punctuation/space.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $kept = array_filter($sentences, fn ($s) => ! self::isBad($s));

        return trim(implode(' ', $kept));
    }

    /** True if the text still contains a leftover placeholder/template token. */
    public static function hasPlaceholder(?string $text): bool
    {
        if (blank($text)) {
            return false;
        }

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
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
