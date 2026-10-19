<?php

namespace App\Support;

/**
 * Deterministic checks of a draft against the research package's polls, legal layers, petitions and appearances
 * (see ResearchAnalyst::politicalBlock). Findings have the same shape as ExactQuoteVerifier's and join the fact-check.
 *
 *  - Polls: a percentage is tied to its population and its measure. 74% of decided voters is not 74% of Albertans;
 *    "believes the movement will continue" is not support for separation.
 *  - Legal: a non-binding vote must not be written as if it changed the legal status by itself.
 *  - Petitions: signatures submitted are not signatures verified.
 *  - Appearances: no needless "reports differ" about a date an official record or transcript settles.
 * No model calls.
 */
class PoliticalCheck
{
    private const QUALIFIER = [
        'decided_voters' => '/\bdecided\b/i',
        'likely_voters' => '/\blikely\b/i',
        'registered_voters' => '/\bregistered\b/i',
        'eligible_voters' => '/\beligible\b/i',
    ];

    private const SUPPORT_WORDS = '/\b(support|supports|supported|favou?r|favou?red|would vote|will vote|vote to|in favou?r|backing|back)\b/i';

    private const EXPECT_WORDS = '/\b(expect|expects|expected|believe|believes|believed|think|thinks|will continue|never be an end|never end|movement will|continue)\b/i';

    /** @return array<int,array{claim:string,problem:string,severity:string,fix:string}> */
    public static function findings(string $bodyHtml, ?array $package): array
    {
        if (! $package) {
            return [];
        }
        $plain = html_entity_decode(strip_tags(str_replace(['</p>', '</h2>'], [" \n", " \n"], $bodyHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\n+/u', $plain) ?: [])));

        return array_merge(
            self::polls($sentences, $package),
            self::legal($sentences, $package),
            self::petitions($sentences, $package),
            self::appearances($sentences, $package),
        );
    }

    private static function polls(array $sentences, array $package): array
    {
        $out = [];
        foreach ((array) ($package['polls'] ?? []) as $poll) {
            $population = (string) ($poll['population'] ?? '');
            $measure = (string) ($poll['measure'] ?? '');
            foreach ((array) ($poll['results'] ?? []) as $r) {
                $pct = self::pct((string) ($r['percent'] ?? ''));
                if ($pct === null) {
                    continue;
                }
                foreach ($sentences as $s) {
                    if (! preg_match('/(?<![\d.])' . preg_quote($pct, '/') . '(?:\.0)?\s?(?:%|per\s?cent|percent)/i', $s)) {
                        continue;
                    }
                    $org = (string) ($poll['organization'] ?? 'the poll');
                    // Population: a sub-group's percentage must name the sub-group when it names a general population.
                    $qual = self::QUALIFIER[$population] ?? null;
                    if ($qual && preg_match('/\b(Albertans|residents|voters|people|respondents|adults|Canadians|everyone|all)\b/i', $s) && ! preg_match($qual, $s)) {
                        $out[] = [
                            'claim' => $s,
                            'problem' => "{$pct}% is a result for " . str_replace('_', ' ', $population) . " in the {$org} poll, but the sentence presents it for a general population without saying so.",
                            'severity' => 'critical',
                            'fix' => 'State the population exactly (for example "among decided voters") or use the figure that covers all respondents.',
                        ];
                    }
                    // Measure: expectation vs voting intention.
                    if ($measure === 'expectation' && preg_match(self::SUPPORT_WORDS, $s) && ! preg_match(self::EXPECT_WORDS, $s)) {
                        $out[] = [
                            'claim' => $s,
                            'problem' => "{$pct}% in the {$org} poll measures what respondents EXPECT, not whether they support an option.",
                            'severity' => 'critical',
                            'fix' => 'Describe it as the share who expect or believe it, using the question the poll asked; do not present it as support or a voting intention.',
                        ];
                    }
                    if ($measure === 'voting_intention' && preg_match(self::EXPECT_WORDS, $s) && ! preg_match(self::SUPPORT_WORDS, $s)) {
                        $out[] = [
                            'claim' => $s,
                            'problem' => "{$pct}% in the {$org} poll is a voting intention, but the sentence describes it as an expectation or belief about the future.",
                            'severity' => 'critical',
                            'fix' => 'Describe it as the share who would vote that way, with its population.',
                        ];
                    }
                    break;
                }
            }
        }

        return $out;
    }

    private static function legal(array $sentences, array $package): array
    {
        $nonBinding = collect((array) ($package['legal'] ?? []))->contains(fn ($l) => ($l['status'] ?? '') === 'non_binding_referendum');
        if (! $nonBinding) {
            return [];
        }
        $out = [];
        foreach ($sentences as $s) {
            $implies = preg_match('/\b(would|will|could|can|automatically|immediately|then)\b[^.]{0,60}\b(separate|secede|leave canada|become independent|declare independence|take effect|change(?:s|d)? (?:the )?(?:province|alberta)[’\']?s? (?:legal|constitutional) status)/i', $s)
                || preg_match('/\b(vote|referendum|result|win)\b[^.]{0,40}\b(means|makes|will make|would make|triggers|results in)\b[^.]{0,40}\b(independen|separat|secession)/i', $s);
            $qualified = preg_match('/\b(non-?binding|not binding|does not|would not|cannot|can’t|can\'t|only|later|second|another|binding referendum|legal process|first step|constitution)\b/i', $s);
            if ($implies && ! $qualified) {
                $out[] = [
                    'claim' => $s,
                    'problem' => 'The referendum is non-binding in the research, but this sentence implies a win changes the legal or constitutional status by itself.',
                    'severity' => 'critical',
                    'fix' => 'Separate what voters decide now, what the government says it will do next and what the law actually permits (a later binding referendum, constitutional requirements, court findings).',
                ];
            }
        }

        return $out;
    }

    private static function petitions(array $sentences, array $package): array
    {
        $out = [];
        foreach ((array) ($package['petitions'] ?? []) as $p) {
            $submitted = self::num((string) ($p['signatures_submitted'] ?? ''));
            $verified = self::num((string) ($p['signatures_verified'] ?? ''));
            if (! $submitted || ($verified && abs($verified - $submitted) / $submitted < 0.01)) {
                continue;
            }
            foreach ($sentences as $s) {
                if (self::mentionsNumber($s, $submitted) && preg_match('/\b(verified|valid|certified|confirmed|approved|counted)\b/i', $s) && ! preg_match('/\b(submitted|handed in|delivered|claimed|collected|filed)\b/i', $s)) {
                    $out[] = [
                        'claim' => $s,
                        'problem' => 'The figure ' . number_format($submitted) . ' is the number of signatures SUBMITTED' . ($verified ? ' (the election authority verified ' . number_format($verified) . ')' : '') . ', but the sentence calls it verified.',
                        'severity' => 'critical',
                        'fix' => 'Say how many were submitted and how many the election authority verified, and which petition each figure belongs to.',
                    ];
                }
            }
        }

        return $out;
    }

    private static function appearances(array $sentences, array $package): array
    {
        $settled = collect((array) ($package['appearances'] ?? []))->first(fn ($a) => in_array($a['verified_by'] ?? '', ['official_record', 'transcript'], true));
        if (! $settled) {
            return [];
        }
        $out = [];
        foreach ($sentences as $s) {
            if (preg_match('/\b(reports? (?:differ|disagree|conflict)|conflicting reports|accounts differ|it is unclear whether|unclear whether|differ on whether)\b/i', $s)
                && preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday|oct\.?|speech|rally|appeared|remarks?)\b/i', $s)) {
                $out[] = [
                    'claim' => $s,
                    'problem' => 'The article tells readers that reports differ about when or where a speech or appearance took place, but the research found an official record or transcript that settles it ('
                        . ($settled['person'] ?? '') . ', ' . ($settled['date'] ?? '') . ', ' . ($settled['location'] ?? '') . ').',
                    'severity' => 'minor',
                    'fix' => 'State the verified date and place, and keep separate appearances by the same person separate.',
                ];
            }
        }

        return $out;
    }

    private static function pct(string $v): ?string
    {
        if (! preg_match('/\d+(?:\.\d+)?/', $v, $m)) {
            return null;
        }

        return str_contains($m[0], '.') ? rtrim(rtrim($m[0], '0'), '.') : $m[0];
    }

    private static function num(string $v): ?float
    {
        $v = strtolower(trim($v));
        if (! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(thousand|k|million)?/', $v, $m)) {
            return null;
        }
        $n = (float) str_replace(',', '', $m[1]);

        return $n * (['thousand' => 1e3, 'k' => 1e3, 'million' => 1e6][$m[2] ?? ''] ?? 1);
    }

    private static function mentionsNumber(string $sentence, float $n): bool
    {
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?\s*(?:thousand|k|million)?/i', $sentence, $m)) {
            foreach ($m[0] as $x) {
                $v = self::num($x);
                if ($v && abs($v - $n) / $n < 0.12) {
                    return true;   // "about 300,000" for a submitted 301,500
                }
            }
        }

        return false;
    }
}
