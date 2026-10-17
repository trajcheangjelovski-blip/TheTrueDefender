<?php

namespace App\Support;

/** Reject unsupported full names attached to people in a draft. */
class NamedEntityVerifier
{
    /**
     * Check names introduced with a role or political label against the actual
     * research excerpts. A surname does not authorize expansion from model memory.
     *
     * @param array<int,array{name?:string,title?:string,text:string}> $sources
     * @param array<int,array<string,mixed>> $existingIssues
     * @return array<int,array{claim:string,problem:string,severity:string,fix:string}>
     */
    public static function findings(string $bodyHtml, array $sources, array $existingIssues = []): array
    {
        $article = html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $evidence = '';
        foreach ($sources as $source) {
            $evidence .= ' ' . ($source['name'] ?? '') . ' ' . ($source['title'] ?? '') . ' ' . ($source['text'] ?? '');
        }
        $evidence = html_entity_decode(strip_tags($evidence), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $pattern = '/\b(?:Democratic candidate|Republican candidate|former state representative|state representative|President|Governor|Gov\.?|Senator|Sen\.?|Representative|representative|Rep\.?|Democrat|Republican|candidate)\s+([\p{Lu}][\p{L}\p{M}’\'.-]*(?:\s+[\p{Lu}][\p{L}\p{M}’\'.-]*){0,2})/u';
        if (! preg_match_all($pattern, $article, $matches)) {
            return [];
        }

        $evidenceWords = array_fill_keys(self::words($evidence), true);
        $out = [];
        foreach ($matches[1] as $name) {
            preg_match_all('/[\p{L}\p{M}]+/u', $name, $nameMatches);
            $missing = array_values(array_filter($nameMatches[0], fn ($word) => ! isset($evidenceWords[mb_strtolower($word)])));
            if ($missing === []) {
                continue;
            }

            $duplicate = collect($existingIssues)->contains(fn ($issue) =>
                str_contains(mb_strtolower((string) ($issue['claim'] ?? '')), mb_strtolower($name)));
            if ($duplicate) {
                continue;
            }

            $out[] = [
                'claim' => $name,
                'problem' => 'The person’s name includes “' . implode(' ', $missing) . '”, which does not appear in the supplied research excerpts. Do not expand a partial name from memory.',
                'severity' => 'critical',
                'fix' => 'Use only the name form present in the research evidence, or omit the identification.',
            ];
        }

        return $out;
    }

    /** @return string[] */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}]+/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0]));
    }
}
