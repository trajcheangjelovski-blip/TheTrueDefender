<?php

namespace App\Support;

/** Free, deterministic checks on a draft against its evidence packets (no model calls). */
class DraftCheck
{
    private const MONTH = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?';

    /** Month-day dates written in the article that no packet states (e.g. a vote date inferred from a publication date). */
    public static function unsupportedDates(string $bodyHtml, array $packets): array
    {
        $body = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $evidence = html_entity_decode(implode("\n", array_map(fn ($p) => ($p['text'] ?? '') . ' ' . ($p['published_at'] ?? '') . ' ' . ($p['modified_at'] ?? ''), $packets)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $known = self::dates($evidence);
        $out = [];
        foreach (self::dates($body) as $key => $label) {
            if (! isset($known[$key])) {
                $out[] = $label;
            }
        }

        return array_values($out);
    }

    /** Packets with a URL exist but the article links to none of them (the policy asks for the exact source beside each claim). */
    public static function missingLinks(string $bodyHtml, array $packets): bool
    {
        return count(array_filter(array_column($packets, 'url'))) > 0 && ! preg_match('/<a\s[^>]*href=/i', $bodyHtml);
    }

    /** @return array<string,string> "mon-day" => as written */
    private static function dates(string $text): array
    {
        $found = [];
        $m = self::MONTH;
        preg_match_all("/\b{$m}\s+(\d{1,2})(?:st|nd|rd|th)?\b(?!\d)/i", $text, $a, PREG_SET_ORDER);
        foreach ($a as $x) {
            $found[strtolower(substr($x[1], 0, 3)) . '-' . (int) $x[2]] = trim($x[0]);
        }
        preg_match_all("/\b(\d{1,2})(?:st|nd|rd|th)?\s+{$m}\b/i", $text, $b, PREG_SET_ORDER);
        foreach ($b as $x) {
            $found[strtolower(substr($x[2], 0, 3)) . '-' . (int) $x[1]] = trim($x[0]);
        }
        // ISO dates in metadata
        preg_match_all('/\b20\d\d-(\d\d)-(\d\d)/', $text, $c, PREG_SET_ORDER);
        foreach ($c as $x) {
            $found[strtolower(date('M', mktime(0, 0, 0, (int) $x[1], 1))) . '-' . (int) $x[2]] = $x[0];
        }

        return $found;
    }
}
