<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Who reported what first? Deterministic source-relationship detection for evidence packets.
 *
 * Five websites repeating one outlet's interview are ONE piece of reporting, not five confirmations.
 * For each packet this decides whether it is original reporting or derived from another packet, and
 * groups packets that rest on the same reporting. A packet is derived when:
 *   - it names another packet's outlet as the source of its information ("CBS Boston reported",
 *     "told WBZ", "according to the Associated Press", "citing …"), or
 *   - it shares long runs of identical wording or the same long direct quotations with another packet
 *     (the earlier-published one, or the one that is attributed, is the origin), or
 *   - it is a known aggregator / content-rewriting site and a related packet exists.
 * Publication date alone never makes a source the origin: attribution decides first.
 *
 * Pure code, no model calls. The output is advisory evidence for the research, writing and
 * fact-check stages and drives the "distinct source" count (independent groups, not websites).
 */
class SourceRelations
{
    /** Hosts that rewrite or summarize other outlets' reporting. */
    private const AGGREGATORS = [
        'sunnyskyz.com', 'hoodline.com', 'world-today-news.com', 'dagens.com', 'newsbreak.com', 'msn.com',
        'ground.news', 'flipboard.com', 'smartnews.com', 'newsnow.co.uk', 'upworthy.com', 'goodnewsnetwork.org',
        'yahoo.com', 'news.google.com', 'aol.com', 'dailyhunt.in', 'bignewsnetwork.com', 'newsbreak.com',
    ];

    /** Verbs/phrases that attribute information to another outlet. */
    private const ATTRIBUTION = '(?:according to|reported by|reports? from|reporting by|told|citing|cited by|as reported by|first reported by|via|per|originally (?:reported|published) (?:by|in|on)|tells|spoke (?:to|with)|interview with|said (?:in|on) (?:an? )?(?:interview|statement) (?:with|to))';

    /**
     * @param array<int,array> $packets sources with name, url, text, published_at
     * @return array<int,array{group:int,origin:bool,relation:string,derived_from:?int,derived_from_name:?string,reasons:array<int,string>}>
     */
    public static function analyze(array $packets): array
    {
        $n = count($packets);
        $texts = [];
        $shingles = [];
        $quotes = [];
        foreach ($packets as $i => $p) {
            $texts[$i] = self::norm((string) ($p['text'] ?? ''));
            $shingles[$i] = self::shingles($texts[$i], 8);
            $quotes[$i] = self::quotes((string) ($p['text'] ?? ''));
        }

        $parent = range(0, max(0, $n - 1));
        $derivedFrom = array_fill(0, $n, null);
        $reasons = array_fill(0, $n, []);
        $find = function (int $x) use (&$parent, &$find): int {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        // 1. Explicit attribution to another packet's outlet.
        foreach ($packets as $i => $p) {
            foreach ($packets as $j => $q) {
                if ($i === $j) {
                    continue;
                }
                foreach (self::outletNames($q) as $name) {
                    if (preg_match('/' . self::ATTRIBUTION . '\s+(?:the\s+)?' . preg_quote($name, '/') . '\b/iu', (string) ($p['text'] ?? ''))
                        || preg_match('/\b' . preg_quote($name, '/') . '\s+(?:reported|said|reports|first reported|interviewed|spoke)\b/iu', (string) ($p['text'] ?? ''))) {
                        if ($derivedFrom[$i] === null && ! self::isDerivedFrom($derivedFrom, $j, $i)) {
                            $derivedFrom[$i] = $j;
                            $reasons[$i][] = "names {$name} as the source of its information";
                            $union($i, $j);
                        }
                        break;
                    }
                }
            }
        }

        // 2. Copied wording or the same long quotations.
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $shared = self::containment($shingles[$i], $shingles[$j]);
                $sharedQuotes = array_intersect($quotes[$i], $quotes[$j]);
                if ($shared < 0.25 && count($sharedQuotes) < 2) {   // one shared quotation alone is not enough: independent reporters quote the same official
                    continue;
                }
                $union($i, $j);
                if ($derivedFrom[$i] !== null || $derivedFrom[$j] !== null) {
                    continue;   // attribution already told us who copied whom
                }
                // The origin is the packet the other one rests on: earlier published, then longer.
                [$origin, $copy] = self::order($packets, $i, $j);
                $derivedFrom[$copy] = $origin;
                $reasons[$copy][] = $shared >= 0.25
                    ? sprintf('%d%% of its wording matches %s', (int) round($shared * 100), $packets[$origin]['name'] ?? 'another source')
                    : 'repeats several direct quotations first found in ' . ($packets[$origin]['name'] ?? 'another source');
            }
        }

        // 3. Aggregators are never independent of the reporting they summarize.
        $groupsBefore = [];
        foreach ($packets as $i => $p) {
            $groupsBefore[$find($i)][] = $i;
        }
        foreach ($packets as $i => $p) {
            if (! self::isAggregator($p)) {
                continue;
            }
            if ($derivedFrom[$i] === null) {
                $others = array_values(array_filter(array_keys($packets), fn ($k) => $k !== $i && ! self::isAggregator($packets[$k])));
                if ($others) {
                    // Treat the best non-aggregator packet as the likely origin only when no better link exists.
                    $reasons[$i][] = 'content aggregator: summarizes other outlets, not independent confirmation';
                    $derivedFrom[$i] = $others[0];
                    $union($i, $others[0]);
                } else {
                    $reasons[$i][] = 'content aggregator';
                }
            } else {
                $reasons[$i][] = 'content aggregator';
            }
        }

        // Resolve groups to small ids and mark the origin of each group.
        $ids = [];
        $out = [];
        foreach ($packets as $i => $p) {
            $root = $find($i);
            $ids[$root] ??= count($ids) + 1;
            $origin = self::root($derivedFrom, $i);
            $out[$i] = [
                'group' => $ids[$root],
                'origin' => $derivedFrom[$i] === null,
                'relation' => self::relation($p, $derivedFrom[$i]),
                'derived_from' => $derivedFrom[$i] === null ? null : self::root($derivedFrom, $i),
                'derived_from_name' => $derivedFrom[$i] === null ? null : ($packets[$origin]['name'] ?? null),
                'reasons' => array_values(array_unique($reasons[$i])),
            ];
        }

        return $out;
    }

    /** Number of independent pieces of reporting (groups), not websites. */
    public static function independentCount(array $packets): int
    {
        $groups = [];
        foreach ($packets as $p) {
            if (isset($p['group'])) {
                $groups[(int) $p['group']] = true;
            }
        }

        return $groups ? count($groups) : count($packets);
    }

    private static function relation(array $p, ?int $derived): string
    {
        if (self::isAggregator($p)) {
            return 'aggregator';
        }

        return $derived === null ? 'original' : 'derived';
    }

    private static function isAggregator(array $p): bool
    {
        $host = Str::lower(preg_replace('/^www\./', '', (string) parse_url((string) ($p['url'] ?? ''), PHP_URL_HOST)));
        foreach (self::AGGREGATORS as $a) {
            if ($host === $a || str_ends_with($host, '.' . $a)) {
                return true;
            }
        }

        return false;
    }

    /** Names a packet's outlet goes by: its publisher name plus a few common short forms. */
    private static function outletNames(array $p): array
    {
        $name = trim((string) ($p['name'] ?? ''));
        $names = $name !== '' ? [$name] : [];
        $host = Str::lower(preg_replace('/^www\./', '', (string) parse_url((string) ($p['url'] ?? ''), PHP_URL_HOST)));
        $path = (string) parse_url((string) ($p['url'] ?? ''), PHP_URL_PATH);
        // CBS local stations carry a city in the URL path: cbsnews.com/boston/... → "CBS Boston", "WBZ".
        if (str_ends_with($host, 'cbsnews.com') && preg_match('#^/([a-z]+)/#', $path, $m) && ! in_array($m[1], ['news', 'video', 'live'], true)) {
            $names[] = 'CBS ' . ucfirst($m[1]);
            if ($m[1] === 'boston') {
                $names[] = 'WBZ-TV';
                $names[] = 'WBZ';
            }
        }
        $short = [
            'apnews.com' => ['The Associated Press', 'AP'], 'reuters.com' => ['Reuters'], 'foxnews.com' => ['Fox News'],
            'nypost.com' => ['The New York Post', 'New York Post'], 'abcnews.com' => ['ABC News'], 'nbcnews.com' => ['NBC News'],
            'breitbart.com' => ['Breitbart'], 'kyivpost.com' => ['Kyiv Post'],
        ];
        foreach ($short as $h => $alts) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                $names = array_merge($names, $alts);
            }
        }

        return array_values(array_unique(array_filter($names, fn ($x) => mb_strlen($x) >= 3)));
    }

    private static function isDerivedFrom(array $derivedFrom, int $candidateParent, int $child): bool
    {
        // Would making $child derived from $candidateParent create a cycle?
        $seen = [];
        $x = $candidateParent;
        while ($x !== null && ! isset($seen[$x])) {
            if ($x === $child) {
                return true;
            }
            $seen[$x] = true;
            $x = $derivedFrom[$x] ?? null;
        }

        return false;
    }

    private static function root(array $derivedFrom, int $i): int
    {
        $seen = [];
        while (($derivedFrom[$i] ?? null) !== null && ! isset($seen[$i])) {
            $seen[$i] = true;
            $i = $derivedFrom[$i];
        }

        return $i;
    }

    /** @return array{0:int,1:int} [origin, copy] */
    private static function order(array $packets, int $i, int $j): array
    {
        $ti = self::ts($packets[$i]);
        $tj = self::ts($packets[$j]);
        if ($ti !== null && $tj !== null && $ti !== $tj) {
            return $ti < $tj ? [$i, $j] : [$j, $i];
        }
        // No usable dates: a non-aggregator, then the longer text, is the origin.
        $ai = self::isAggregator($packets[$i]);
        $aj = self::isAggregator($packets[$j]);
        if ($ai !== $aj) {
            return $ai ? [$j, $i] : [$i, $j];
        }

        return mb_strlen((string) ($packets[$i]['text'] ?? '')) >= mb_strlen((string) ($packets[$j]['text'] ?? '')) ? [$i, $j] : [$j, $i];
    }

    private static function ts(array $p): ?int
    {
        try {
            return filled($p['published_at'] ?? null) ? \Illuminate\Support\Carbon::parse($p['published_at'])->getTimestamp() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function norm(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)) ?? '');
    }

    /** @return array<string,true> */
    private static function shingles(string $norm, int $n): array
    {
        $w = array_values(array_filter(explode(' ', $norm)));
        $out = [];
        for ($i = 0; $i + $n <= count($w); $i++) {
            $out[implode(' ', array_slice($w, $i, $n))] = true;
        }

        return $out;
    }

    /** Share of the SMALLER text's shingles that also occur in the other. */
    private static function containment(array $a, array $b): float
    {
        $small = count($a) <= count($b) ? $a : $b;
        $large = $small === $a ? $b : $a;
        if (count($small) < 5) {
            return 0.0;
        }

        return count(array_intersect_key($small, $large)) / count($small);
    }

    /** Long direct quotations (6+ words), normalized. @return array<int,string> */
    private static function quotes(string $text): array
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/“([^”]{25,})”|"([^"\n]{25,})"/u', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $q = self::norm($x[1] !== '' ? $x[1] : ($x[2] ?? ''));
            if (str_word_count($q) >= 6) {
                $out[] = $q;
            }
        }

        return array_values(array_unique($out));
    }
}
