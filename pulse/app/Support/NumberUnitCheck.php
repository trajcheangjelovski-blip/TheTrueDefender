<?php

namespace App\Support;

/**
 * Deterministic number-and-unit validation of a draft against its evidence packets.
 *
 * Quantities are compared WITH their units. Gallons, barrels, liters, metric tons, pounds, miles,
 * acres, percentages and dollar amounts are different measures: "1 million gallons" is not
 * "1 million tons", and nothing here ever converts one into another. A draft quantity is
 *   - fine when a packet states the same number in the same unit (rounding within 1% is ignored),
 *   - a CRITICAL unit mismatch when a packet states that number only in a different unit,
 *   - a CRITICAL unsupported figure when no packet states that number in any unit,
 *   - minor when it differs from a same-unit figure only by rounding (1% to 5%).
 * Packets that report quantities of the same thing in incompatible units (for example diesel in
 * gallons in one packet and in metric tons in another) are reported as an unresolved discrepancy:
 * a draft that states either figure as established fact is flagged critical so a person decides.
 *
 * No model calls. Findings use the same shape as ExactQuoteVerifier, so they join the fact-check.
 */
class NumberUnitCheck
{
    /** unit word (regex) => [class, canonical label] */
    private const UNITS = [
        'gallons?|gal' => ['volume_gal', 'gallons'],
        'barrels?|bbl|bpd' => ['volume_bbl', 'barrels'],
        'liters?|litres?' => ['volume_l', 'liters'],
        'metric tons?|tonnes?|mt' => ['mass_t', 'metric tons'],
        'short tons?|tons?' => ['mass_ton', 'tons'],
        'pounds?|lbs?' => ['mass_lb', 'pounds'],
        'kilograms?|kgs?' => ['mass_kg', 'kilograms'],
        'miles?' => ['len_mi', 'miles'],
        'kilometers?|kilometres?|km' => ['len_km', 'kilometers'],
        'acres?' => ['area_ac', 'acres'],
        'percent|per cent|%' => ['pct', 'percent'],
        'degrees?' => ['deg', 'degrees'],
    ];

    private const USD = ['usd', 'dollars'];

    /** Counted things. They are NOT interchangeable: 500,000 people is not 500,000 households or customers. noun regex => [class, label] */
    private const COUNTS = [
        'people|persons|residents|civilians|inhabitants' => ['cnt_people', 'people'],
        'households|families' => ['cnt_household', 'households'],
        'customers|consumers|subscribers' => ['cnt_customer', 'customers'],
        'homes|houses|buildings|apartments' => ['cnt_building', 'homes or buildings'],
        'facilities|substations|plants|sites|installations' => ['cnt_facility', 'facilities'],
        'missiles' => ['cnt_missile', 'missiles'],
        'drones' => ['cnt_drone', 'drones'],
    ];

    private const MULT = ['thousand' => 1e3, 'k' => 1e3, 'million' => 1e6, 'mln' => 1e6, 'm' => 1e6, 'billion' => 1e9, 'bn' => 1e9, 'trillion' => 1e12];

    /** @return array<int,array{claim:string,problem:string,severity:string,fix:string}> */
    public static function findings(string $bodyHtml, array $sources): array
    {
        $body = self::plain($bodyHtml);
        $draft = self::quantities($body);
        if (! $draft) {
            return [];
        }
        $evidence = [];
        foreach ($sources as $s) {
            $evidence = array_merge($evidence, self::quantities(self::plain((string) ($s['text'] ?? ''))));
        }
        $allNumbers = self::allNumbers(implode("\n", array_map(fn ($s) => self::plain((string) ($s['text'] ?? '')), $sources)));

        $out = [];
        $seen = [];
        foreach ($draft as $q) {
            if (! empty($q['vague'])) {
                continue;   // "hundreds of thousands of gallons": no number to match, only its unit is compared (below)
            }
            $key = $q['class'] . ':' . $q['value'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $evidence = array_values(array_filter($evidence, fn ($e) => empty($e['vague'])));
            $same = array_filter($evidence, fn ($e) => $e['class'] === $q['class']);
            $exact = array_filter($same, fn ($e) => self::within($e['value'], $q['value'], 0.01));
            if ($exact) {
                continue;
            }
            $near = array_filter($same, fn ($e) => self::within($e['value'], $q['value'], 0.05));
            if ($near) {
                $out[] = [
                    'claim' => $q['text'],
                    'problem' => 'The figure differs slightly from the source (' . self::first($near)['text'] . '); it may be a rounding difference.',
                    'severity' => 'minor',
                    'fix' => 'Use the source\'s figure exactly, or say "about".',
                ];

                continue;
            }
            $otherUnit = array_filter($evidence, fn ($e) => $e['class'] !== $q['class'] && self::family($e['class']) === self::family($q['class']) && self::within($e['value'], $q['value'], 0.01));
            if ($otherUnit) {
                $e = self::first($otherUnit);
                $out[] = [
                    'claim' => $q['text'],
                    'problem' => 'Unit mismatch: the draft gives this number in ' . $q['label'] . ', but a source states it in ' . $e['label']
                        . ' (“' . $e['text'] . '”). These are different measures and must not be swapped or converted.',
                    'severity' => 'critical',
                    'fix' => 'State the quantity exactly as the source does (' . $e['text'] . ') with its own unit, or remove it.',
                ];

                continue;
            }
            // Percentages and small counts appear in many harmless forms; only flag larger/unit-bearing figures.
            if (in_array($q['class'], ['volume_gal', 'volume_bbl', 'volume_l', 'mass_t', 'mass_ton', 'mass_lb', 'mass_kg', 'usd'], true) && ! self::numberAppears($q['value'], $allNumbers)) {
                $out[] = [
                    'claim' => $q['text'],
                    'problem' => 'No source states this quantity (' . $q['text'] . ').',
                    'severity' => 'critical',
                    'fix' => 'Remove the figure or replace it with the quantity a source states.',
                ];
            }
        }

        // Unresolved discrepancy between the packets themselves.
        foreach (self::packetConflicts($sources) as $c) {
            foreach ($draft as $q) {
                foreach ([$c['a'], $c['b']] as $side) {
                    $sameFigure = ! empty($q['vague']) || ! empty($side['vague'])
                        ? array_intersect($q['context'], $side['context']) !== []
                        : self::within($q['value'], $side['value'], 0.01);
                    if ($q['class'] === $side['class'] && $sameFigure) {
                        $out[] = [
                            'claim' => $q['text'],
                            'problem' => 'Unresolved unit discrepancy between sources: ' . $c['a']['text'] . ' (' . $c['a']['source'] . ') versus '
                                . $c['b']['text'] . ' (' . $c['b']['source'] . '). The draft states one of them as established.',
                            'severity' => 'critical',
                            'fix' => 'Attribute each figure to its source and say they are reported in different units, or remove the quantity pending an editor.',
                        ];

                        break 3;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Quantities about the same thing reported in incompatible unit classes by different packets.
     *
     * @return array<int,array{a:array,b:array}>
     */
    public static function packetConflicts(array $sources): array
    {
        $items = [];
        foreach ($sources as $si => $s) {
            foreach (self::quantities(self::plain((string) ($s['text'] ?? ''))) as $q) {
                if (in_array($q['class'], ['pct', 'deg', 'len_mi', 'len_km', 'area_ac'], true) || str_starts_with($q['class'], 'cnt_')) {
                    continue;   // counted things are checked by category in findings(), not as cross-packet conflicts
                }
                $items[] = $q + ['source' => (string) ($s['name'] ?? ('source ' . ($si + 1))), 'si' => $si];
            }
        }
        $out = [];
        for ($i = 0; $i < count($items); $i++) {
            for ($j = $i + 1; $j < count($items); $j++) {
                $a = $items[$i];
                $b = $items[$j];
                if ($a['si'] === $b['si'] || self::family($a['class']) !== self::family($b['class']) || $a['class'] === $b['class']) {
                    continue;
                }
                if (array_intersect($a['context'], $b['context'])) {
                    $out[] = ['a' => $a, 'b' => $b];
                }
            }
        }

        return array_slice($out, 0, 5);
    }

    /** volume and mass quantities of the same commodity can look alike but are not interchangeable. */
    private static function family(string $class): string
    {
        return match (true) {
            str_starts_with($class, 'volume'), str_starts_with($class, 'mass') => 'commodity',
            str_starts_with($class, 'cnt_') => 'count',
            default => $class,
        };
    }

    /** @return array<int,array{value:float,class:string,label:string,text:string,context:array<int,string>}> */
    private static function quantities(string $text): array
    {
        $unitAlt = implode('|', array_keys(self::UNITS));
        $mult = implode('|', array_keys(self::MULT));
        $multAlt0 = $mult;
        $re = "/(\\\$)?\\b(\\d{1,3}(?:,\\d{3})+|\\d+)(\\.\\d+)?\\s*(?:(?<m>{$mult})\\b\\.?)?\\s*(?<u>{$unitAlt})(?![A-Za-z])/iu";
        $out = [];
        if (! preg_match_all($re, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $ms = [];   // no numeric quantities; vague magnitudes and dollar amounts are still looked for below
        }
        foreach ($ms as $m) {
            $unitWord = mb_strtolower($m['u'][0]);
            $class = null;
            $label = null;
            foreach (self::UNITS as $pat => [$c, $l]) {
                if (preg_match('/^(?:' . $pat . ')$/iu', $unitWord)) {
                    $class = $c;
                    $label = $l;
                    break;
                }
            }
            if (! $class) {
                continue;
            }
            $num = (float) (str_replace(',', '', $m[2][0]) . ($m[3][0] ?? ''));
            $mkey = mb_strtolower($m['m'][0] ?? '');
            if ($mkey !== '' && isset(self::MULT[$mkey])) {
                $num *= self::MULT[$mkey];
            }
            $pos = $m[0][1];
            $window = mb_strtolower(substr($text, max(0, $pos - 70), 150));
            $out[] = ['value' => $num, 'class' => $class, 'label' => $label, 'text' => trim($m[0][0]), 'context' => self::contextWords($window)];
        }

        // Counted things: "28 substations", "more than half a million people", "16 ballistic missiles" (up to 3 words
        // may sit between the number and the noun). The number may be written "half a million".
        $countAlt = implode('|', array_keys(self::COUNTS));
        $half = 'half\\s+a\\s+million|half-million';
        $countRe = "/(?:(?<n>\\d{1,3}(?:,\\d{3})+|\\d+)(?<d>\\.\\d+)?\\s*(?:(?<m>{$multAlt0})\\b)?|(?<h>{$half}))(?:\\s+[A-Za-z-]+){0,3}?\\s+(?<u>{$countAlt})\\b/iu";
        if (preg_match_all($countRe, $text, $cs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($cs as $m) {
                $noun = mb_strtolower($m['u'][0]);
                foreach (self::COUNTS as $pat => [$c, $l]) {
                    if (preg_match('/^(?:' . $pat . ')$/iu', $noun)) {
                        if (! empty($m['h'][0])) {
                            $num = 500000.0;
                        } else {
                            $num = (float) (str_replace(',', '', $m['n'][0]) . ($m['d'][0] ?? ''));
                            $mkey = mb_strtolower($m['m'][0] ?? '');
                            if ($mkey !== '' && isset(self::MULT[$mkey])) {
                                $num *= self::MULT[$mkey];
                            }
                        }
                        $out[] = ['value' => $num, 'class' => $c, 'label' => $l, 'text' => trim($m[0][0]), 'context' => self::contextWords(mb_strtolower(substr($text, max(0, $m[0][1] - 70), 150)))];
                        break;
                    }
                }
            }
        }

        // Vague magnitudes with a unit: "hundreds of thousands of gallons". No figure to match, but the unit is real.
        if (preg_match_all("/\b(?:hundreds|tens|dozens|thousands|millions|billions)(?:\s+of\s+(?:thousands|millions|billions))?\s+of\s+(?<u>{$unitAlt})(?![A-Za-z])/iu", $text, $vs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($vs as $m) {
                $unitWord = mb_strtolower($m['u'][0]);
                foreach (self::UNITS as $pat => [$c, $l]) {
                    if (preg_match('/^(?:' . $pat . ')$/iu', $unitWord)) {
                        $pos = $m[0][1];
                        $out[] = ['value' => null, 'vague' => true, 'class' => $c, 'label' => $l, 'text' => trim($m[0][0]),
                            'context' => self::contextWords(mb_strtolower(substr($text, max(0, $pos - 70), 150)))];
                        break;
                    }
                }
            }
        }

        // Dollar amounts: $5, $1,200, $2.5 million.
        $multAlt = implode('|', array_keys(self::MULT));
        if (preg_match_all("/\$\s?(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?(?:\s*(?<m>{$multAlt})\b)?/iu", $text, $ds, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($ds as $m) {
                $num = (float) (str_replace(',', '', $m[1][0]) . ($m[2][0] ?? ''));
                $mkey = mb_strtolower($m['m'][0] ?? '');
                if ($mkey !== '' && isset(self::MULT[$mkey])) {
                    $num *= self::MULT[$mkey];
                }
                $pos = $m[0][1];
                $out[] = ['value' => $num, 'class' => self::USD[0], 'label' => self::USD[1], 'text' => trim($m[0][0]),
                    'context' => self::contextWords(mb_strtolower(substr($text, max(0, $pos - 70), 150)))];
            }
        }

        return $out;
    }

    /** Content words near a quantity (used to tell whether two quantities describe the same thing). */
    private static function contextWords(string $window): array
    {
        $stop = array_flip(['the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'were', 'has', 'have', 'had', 'its', 'into', 'over', 'about', 'than', 'said', 'says', 'will', 'would', 'could', 'more', 'less', 'also', 'which', 'their', 'there', 'been', 'being', 'after', 'before', 'hundreds', 'thousands', 'million', 'billion', 'thousand', 'import', 'imports', 'announced', 'announcement']);
        preg_match_all('/[a-z]{4,}/', $window, $m);

        return array_values(array_unique(array_filter($m[0], fn ($w) => ! isset($stop[$w]) && ! preg_match('/^(gallon|barrel|liter|litre|tonne|metric|pound|mile|acre|percent|degree)/', $w))));
    }

    /** @return array<int,float> */
    private static function allNumbers(string $text): array
    {
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $text, $m);
        $vals = [];
        foreach ($m[0] as $n) {
            $vals[] = (float) str_replace(',', '', $n);
        }
        // "2 million" style
        preg_match_all('/(\d+(?:\.\d+)?)\s*(thousand|million|billion|trillion)/i', $text, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            $vals[] = (float) $x[1] * self::MULT[strtolower($x[2])];
        }

        return $vals;
    }

    private static function numberAppears(float $v, array $numbers): bool
    {
        foreach ($numbers as $n) {
            if (self::within($n, $v, 0.001)) {
                return true;
            }
        }

        return false;
    }

    private static function within(float $a, float $b, float $tol): bool
    {
        if ($a == 0.0 && $b == 0.0) {
            return true;
        }

        return abs($a - $b) / max(abs($a), abs($b)) <= $tol;
    }

    private static function first(array $rows): array
    {
        return reset($rows);
    }

    private static function plain(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
