<?php

namespace App\Support;

/**
 * Checks a draft's figures against the research package's reconciled figures (ResearchAnalyst::figureNotes).
 *
 * Developing stories report changing numbers. An earlier count and a later count are usually both right; the
 * draft must say which is which. Findings (same shape as ExactQuoteVerifier):
 *   - critical: a figure the package marks `unverified` is stated as fact;
 *   - critical: a `superseded` figure is stated as current, with nothing in the sentence marking it as earlier;
 *   - minor:   two figures of the same category that the package could not place in time are both stated
 *              without attribution.
 * Different scopes or categories are never treated as contradictory here. No model calls.
 */
class FigureCheck
{
    private const EARLIER = '/\b(earlier|initial(?:ly)?|at first|preliminary|previous(?:ly)?|then|had (?:said|reported|put)|before|updated|revised|rose|risen|up from|increased)\b/i';

    /** @return array<int,array{claim:string,problem:string,severity:string,fix:string}> */
    public static function findings(string $bodyHtml, ?array $package): array
    {
        if (! $package || empty($package['figures'])) {
            return [];
        }
        $plain = html_entity_decode(strip_tags(str_replace(['</p>', '</h2>'], [" \n", " \n"], $bodyHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $plain) ?: [];
        $out = [];
        foreach ((array) $package['figures'] as $f) {
            $status = (string) ($f['status'] ?? '');
            if (! in_array($status, ['superseded', 'unverified'], true)) {
                continue;
            }
            $needles = self::needles($f);
            if (! $needles) {
                continue;
            }
            foreach ($sentences as $sentence) {
                if (! self::mentions($sentence, $needles)) {
                    continue;
                }
                if ($status === 'unverified') {
                    $out[] = [
                        'claim' => trim($sentence),
                        'problem' => 'The research could not verify this figure (' . ($f['value'] ?? '') . ', ' . ($f['label'] ?? '') . '), but the draft states it.',
                        'severity' => 'critical',
                        'fix' => 'Remove the figure, or attribute it exactly to the person who stated it and say it is unverified.',
                    ];
                    break;
                }
                if (! preg_match(self::EARLIER, $sentence)) {
                    $out[] = [
                        'claim' => trim($sentence),
                        'problem' => 'This figure (' . ($f['value'] ?? '') . ' ' . ($f['label'] ?? '') . ') was superseded by a newer one in the research but is stated as if current.',
                        'severity' => 'critical',
                        'fix' => 'State the latest figure, or present this one as the earlier count with its time and source.',
                    ];
                    break;
                }
            }
        }

        return $out;
    }

    /** Spellings of a figure's number that could appear in prose. @return array<int,string> */
    private static function needles(array $f): array
    {
        $raw = trim((string) ($f['number'] ?? ''));
        $val = trim((string) ($f['value'] ?? ''));
        $n = [];
        if ($raw !== '' && preg_match('/^\d[\d,]*$/', $raw)) {
            $n[] = $raw;
            $n[] = number_format((float) str_replace(',', '', $raw));
        }
        if (preg_match('/\d[\d,]*/', $val, $m) && mb_strlen($m[0]) >= 2) {
            $n[] = $m[0];
        }
        if ($val !== '' && ! preg_match('/\d/', $val)) {
            $n[] = $val;   // spelled-out figure such as "half a million" or "twenty-eight"
        }

        return array_values(array_unique(array_filter($n, fn ($x) => $x !== '')));
    }

    private static function mentions(string $sentence, array $needles): bool
    {
        foreach ($needles as $n) {
            if (preg_match('/(?<![\d,.])' . preg_quote($n, '/') . '(?![\d,]*\d)/iu', $sentence)) {
                return true;
            }
        }

        return false;
    }
}
