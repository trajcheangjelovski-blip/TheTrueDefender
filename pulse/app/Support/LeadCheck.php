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

    /** Share of an excerpt sentence's words that already appear in one lead sentence (a longer lead sentence can still be a repeat). */
    public const CONTAINMENT = 0.8;

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
                if (str_word_count($e) >= 8 && (self::jaccard($e, $l) >= self::SENTENCE_SIMILARITY || self::containment($e, $l) >= self::CONTAINMENT)) {
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

    /**
     * Does the final paragraph introduce a separate development that nothing in the headline or opening explains?
     * Flags only a paragraph that (a) shares few content words (under 30%) with the headline and first paragraph, and
     * (b) is marked as a side topic ("also", "separately", "meanwhile"…) or brings a new large number. Related
     * background that explains the event, its history, a dispute or its consequences shares words with the lead
     * and is not flagged. Returns the paragraph text or null.
     */
    public static function unrelatedEnding(string $title, string $bodyHtml): ?string
    {
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $bodyHtml, $m);
        $paras = array_values(array_filter(array_map(fn ($p) => trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[1] ?? [])));
        if (count($paras) < 4) {
            return null;
        }
        $last = $paras[count($paras) - 1];
        if (str_word_count($last) < 15) {
            return null;
        }
        // The lead is judged together with the headline and the paragraphs that develop it (all but the last).
        $topic = $title . ' ' . implode(' ', array_slice($paras, 0, 3));
        $wl = self::words($last);
        $wt = self::words($topic);
        if (! $wl || ! $wt) {
            return null;
        }
        $overlap = count(array_intersect($wl, $wt)) / count($wl);
        $marker = (bool) preg_match('/\b(also|separately|meanwhile|in a separate|elsewhere|on sunday|on saturday|on monday|on friday)\b/i', $last);
        $bigNumber = (bool) preg_match('/\b\d{1,3}(?:,\d{3})+\b|\bmore than \d{3,}\b/i', $last);

        return $overlap < 0.3 && ($marker || $bigNumber) ? $last : null;
    }

    /** Excerpt that merely restates the headline (high word overlap with a similar length). */
    public static function excerptRestatesHeadline(?string $excerpt, string $title): bool
    {
        $e = trim(html_entity_decode(strip_tags((string) $excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($e) < 20 || mb_strlen($title) < 20) {
            return false;
        }

        return self::containment($title, $e) >= 0.85 && str_word_count($e) <= str_word_count($title) + 6;
    }

    /** Pairs of long body sentences that say almost the same thing (not exact repeats; those are caught elsewhere). */
    public static function nearDuplicateSentences(string $bodyHtml): array
    {
        $plain = html_entity_decode(strip_tags(str_replace('</p>', ' </p> ', preg_replace('/<h2[^>]*>.*?<\/h2>/is', ' ', $bodyHtml))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = array_values(array_filter(self::split($plain), fn ($x) => str_word_count($x) >= 12));
        $out = [];
        for ($i = 0; $i < count($s); $i++) {
            for ($j = $i + 1; $j < count($s); $j++) {
                // Sentences that differ in a number or a numbered label state different facts or are templated lists;
                // only wording that repeats with the same figures counts as a near-duplicate.
                if ($s[$i] !== $s[$j] && self::digitTokens($s[$i]) === self::digitTokens($s[$j]) && self::jaccard($s[$i], $s[$j]) >= 0.8) {
                    $out[] = [$s[$i], $s[$j]];
                }
            }
        }

        return array_slice($out, 0, 3);
    }

    /** Tokens containing a digit, sorted: the figures and numbered labels a sentence carries. */
    private static function digitTokens(string $text): array
    {
        preg_match_all('/\S*\d\S*/u', mb_strtolower($text), $m);
        $t = array_map(fn ($x) => trim($x, '.,;:!?"“”()'), $m[0]);
        sort($t);

        return $t;
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

    /** Share of $a's distinct content words that occur in $b. */
    private static function containment(string $a, string $b): float
    {
        $wa = self::words($a);
        $wb = self::words($b);
        if (count($wa) < 6) {
            return 0.0;
        }

        return count(array_intersect($wa, $wb)) / count($wa);
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
