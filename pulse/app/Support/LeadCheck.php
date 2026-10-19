<?php

namespace App\Support;

/**
 * Deterministic checks on how an article opens and ends.
 *
 *  - excerptRepeatsLead: the excerpt and the opening paragraph may share names, places and the central
 *    fact, but must not say the same thing in nearly the same words.
 *  - backgroundEnding: a human-interest or news story should not close on incidental background (how long
 *    a venue has operated, when something was founded) when that is not the point of the story.
 *
 * No model calls. Messages feed the existing fix loop, so the writer is told what to change.
 */
class LeadCheck
{
    /** Sentence-level Jaccard similarity above which an excerpt sentence "repeats" a lead sentence. */
    public const SENTENCE_SIMILARITY = 0.6;

    /** Overall word-set similarity of the excerpt and the first two sentences. */
    public const OVERALL_SIMILARITY = 0.55;

    public static function excerptRepeatsLead(?string $excerpt, string $bodyHtml): bool
    {
        $excerpt = trim(html_entity_decode(strip_tags((string) $excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($excerpt) < 40) {
            return false;
        }
        $lead = self::firstParagraph($bodyHtml);
        if ($lead === '') {
            return false;
        }
        $leadSentences = self::split($lead);
        $exSentences = self::split($excerpt);
        foreach ($exSentences as $e) {
            foreach (array_slice($leadSentences, 0, 3) as $l) {
                if (str_word_count($e) >= 8 && self::jaccard($e, $l) >= self::SENTENCE_SIMILARITY) {
                    return true;
                }
            }
        }

        return self::jaccard($excerpt, implode(' ', array_slice($leadSentences, 0, 2))) >= self::OVERALL_SIMILARITY;
    }

    /** A description of a weak, background-only ending, or null. */
    public static function backgroundEnding(string $bodyHtml): ?string
    {
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $bodyHtml, $m);
        $paras = array_values(array_filter(array_map(fn ($p) => trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[1] ?? [])));
        if (count($paras) < 3) {
            return null;
        }
        $last = $paras[count($paras) - 1];
        $patterns = [
            '/\b(?:has|have)\s+(?:been\s+)?(?:operat(?:ed|ing)|open(?:ed)?|in business|around|running)\s+(?:for|since)\b/i',
            '/\b(?:was|were)\s+(?:founded|established|opened|built|created)\s+(?:in|by|on)\b/i',
            '/\b(?:founded|established|opened)\s+in\s+(?:19|20)\d\d\b/i',
            '/\bfor\s+(?:about|nearly|almost|more than|over)?\s*(?:\w+)\s+years\b.*\b(?:frequent|popular|backdrop|destination)\b/i',
            '/\b(?:runs|hosts|offers)\s+(?:a\s+)?(?:monthly|weekly|annual)\b/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $last)) {
                return $last;
            }
        }

        return null;
    }

    private static function firstParagraph(string $html): string
    {
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /** @return array<int,string> */
    private static function split(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $text) ?: [])));
    }

    private static function words(string $text): array
    {
        $stop = array_flip(['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'for', 'with', 'by', 'as', 'is', 'was', 'were', 'be', 'that', 'this', 'it', 'its', 'from', 'said', 'says']);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_filter($m[0], fn ($w) => ! isset($stop[$w]) && mb_strlen($w) > 1)));
    }

    private static function jaccard(string $a, string $b): float
    {
        $wa = self::words($a);
        $wb = self::words($b);
        if (! $wa || ! $wb) {
            return 0.0;
        }
        $i = count(array_intersect($wa, $wb));
        $u = count(array_unique(array_merge($wa, $wb)));

        return $u ? $i / $u : 0.0;
    }
}
