<?php

namespace App\Support;

/** Deterministically rejects direct quotes absent from every supplied source. */
class ExactQuoteVerifier
{
    /** @return array<int,array{claim:string,problem:string,severity:string,fix:string}> */
    public static function findings(string $bodyHtml, array $sources, array $existingIssues = []): array
    {
        $body = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/“([^”]+)”|(?<!\\w)"([^"\\n]+)"/u', $body, $matches, PREG_SET_ORDER);
        if (! $matches) {
            return [];
        }

        $evidence = array_map(fn ($source) => self::normalize((string) ($source['text'] ?? '')), $sources);
        $findings = [];
        foreach ($matches as $match) {
            $quote = trim((string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')));
            if ($quote === '') {
                continue;
            }

            $segments = preg_split('/(?:…|\\.{3,}|\\[\\s*…\\s*\\])+/u', $quote) ?: [$quote];
            $segments = array_values(array_filter(array_map('trim', $segments)));
            $normalized = array_map(self::normalize(...), $segments);
            $eligible = array_filter($normalized, fn ($part) => str_word_count($part) >= 4 || preg_match('/\\d/', $part));
            if (! $eligible) {
                continue; // Short labels and names are not meaningful direct quotations.
            }

            $alreadyFlagged = collect($existingIssues)->contains(function ($issue) use ($normalized) {
                $problem = (string) ($issue['problem'] ?? '');
                $claim = self::normalize((string) ($issue['claim'] ?? ''));
                $isQuoteIssue = preg_match('/quote|quotation|verbatim|exact wording|quoted/i', $problem);

                return $isQuoteIssue && collect($normalized)->contains(fn ($part) => $part !== '' && str_contains($claim, $part));
            });
            if ($alreadyFlagged) {
                continue; // Avoid duplicating an issue the independent checker already found.
            }

            $verified = collect($evidence)->contains(function ($sourceText) use ($eligible) {
                foreach ($eligible as $segment) {
                    if ($segment !== '' && ! str_contains($sourceText, $segment)) {
                        return false;
                    }
                }

                return true;
            });
            if (! $verified) {
                $combined = self::joinsSeparatePassages($normalized, $evidence);
                $findings[] = [
                    'claim' => '“' . $quote . '”',
                    'problem' => $combined
                        ? 'This quotation joins separate remarks into one quotation: each part appears in a source, but not together as one continuous passage (or words were left out that the source has between them).'
                        : 'This direct quotation does not match a contiguous passage in any supplied source excerpt.',
                    'severity' => 'critical',
                    'fix' => $combined
                        ? 'Quote ONE continuous passage, or put each remark in its own quotation with its own attribution and date, or paraphrase without quotation marks.'
                        : 'Use the exact wording found in a source or paraphrase accurately without quotation marks.',
                ];

                continue;
            }

            // The wording is real. Is it attributed to the right person?
            if ($note = self::attributionProblem($body, $quote, $evidence)) {
                $findings[] = [
                    'claim' => '“' . $quote . '”',
                    'problem' => $note,
                    'severity' => 'minor',
                    'fix' => 'Attribute the quotation to the person the source names, or paraphrase.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Does the quotation consist of passages that each occur in a source, though never together?
     * That is the signature of two remarks stitched into one direct quote.
     *
     * @param array<int,string> $normalizedSegments
     * @param array<int,string> $evidence normalized source texts
     */
    private static function joinsSeparatePassages(array $normalizedSegments, array $evidence): bool
    {
        $parts = [];
        foreach ($normalizedSegments as $seg) {
            // Split a segment where a new sentence plausibly starts (normalized text has no punctuation,
            // so look for the original sentence breaks via the raw segment length instead): use word windows.
            $words = array_values(array_filter(explode(' ', $seg)));
            if (count($words) < 8) {
                $parts[] = $seg;

                continue;
            }
            // Try every split point; if some split gives two halves that each appear but the whole does not,
            // the quotation is a join.
            for ($k = 3; $k <= count($words) - 3; $k++) {
                $a = implode(' ', array_slice($words, 0, $k));
                $b = implode(' ', array_slice($words, $k));
                $inA = collect($evidence)->contains(fn ($t) => str_contains($t, $a));
                $inB = collect($evidence)->contains(fn ($t) => str_contains($t, $b));
                if ($inA && $inB) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Names in the draft sentence that carries the quotation (outside the quote) should appear in the
     * source near the same words. Conservative: only flags when the draft names a person and none of
     * those names occurs within ~600 characters of the quotation in the source.
     */
    private static function attributionProblem(string $body, string $quote, array $evidence): ?string
    {
        $pos = mb_stripos($body, $quote);
        if ($pos === false) {
            return null;
        }
        $before = mb_substr($body, max(0, $pos - 160), min(160, $pos));
        $after = mb_substr($body, $pos + mb_strlen($quote), 120);
        $around = $before . ' ' . $after;
        preg_match_all('/\b[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,})?\b/u', $around, $m);
        $skip = array_flip(['The', 'This', 'That', 'These', 'Those', 'According', 'But', 'And', 'When', 'While', 'After', 'Before', 'Monday', 'Tuesday', 'Wednesday',
            'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November',
            'December', 'Fox', 'News', 'Post', 'Times', 'Reuters', 'Associated', 'Press', 'Facebook', 'Truth', 'Social', 'Update', 'Secretary', 'President', 'Senator',
            'Gov', 'Mayor', 'Judge', 'Chief', 'Director', 'Spokesperson', 'Rep']);
        $names = array_values(array_unique(array_filter(array_map(function ($n) use ($skip) {
            $last = trim(preg_split('/\s+/', $n)[count(preg_split('/\s+/', $n)) - 1]);

            return isset($skip[$last]) || isset($skip[trim(preg_split('/\s+/', $n)[0])]) ? null : mb_strtolower($last);
        }, $m[0]))));
        if (! $names) {
            return null;
        }
        $needle = self::normalize($quote);
        foreach ($evidence as $text) {
            $at = mb_strpos($text, $needle);
            if ($at === false) {
                continue;
            }
            $window = mb_substr($text, max(0, $at - 600), mb_strlen($needle) + 1200);
            foreach ($names as $n) {
                if (str_contains($window, $n)) {
                    return null;
                }
            }
        }

        return 'The sentence attributes this quotation to ' . implode(' / ', array_map('ucfirst', $names)) . ', but the source does not name that person near the quotation.';
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        return trim(preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $text) ?? '');
    }
}
