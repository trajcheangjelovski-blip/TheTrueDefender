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
                $findings[] = [
                    'claim' => '“' . $quote . '”',
                    'problem' => 'This direct quotation does not match a contiguous passage in any supplied source excerpt.',
                    'severity' => 'critical',
                    'fix' => 'Use the exact wording found in a source or paraphrase accurately without quotation marks.',
                ];
            }
        }

        return $findings;
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        return trim(preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $text) ?? '');
    }
}
