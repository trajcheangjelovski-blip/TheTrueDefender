<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * INDEPENDENT FACT-CHECK (pipeline stage 5). A separate model call — not the
 * writer reviewing itself — compares every factual claim in an article against
 * the collected source texts only (never the model's own knowledge).
 *
 * The verdict is decided in CODE from the issues found, not by trusting the
 * model's own verdict: any critical problem blocks publication, and an article
 * with no source evidence can never be "ready".
 *
 * Verdicts: ready | needs_rewrite (fixable errors) | needs_research (claims the
 * sources don't establish) | rejected (insufficient evidence for the story).
 */
class FactChecker
{
    public const VERDICTS = ['ready', 'needs_rewrite', 'needs_research', 'rejected'];

    /**
     * @param array<int,array{name?:string,url?:string,title?:?string,published_at?:?string,text:string}> $sources
     * @param string|null $asOf original publication date (for revisions of older articles)
     */
    public function check(AiClient $ai, string $title, string $bodyHtml, array $sources, ?string $asOf = null, bool $opinion = false): array
    {
        $sources = array_values(array_filter($sources, fn ($s) => str_word_count((string) ($s['text'] ?? '')) >= 60));
        if (! $sources) {
            return $this->result('needs_research', [], 0, [], [], [], false, 'No source evidence was available to verify this article.');
        }

        $evidence = '';
        foreach (array_slice($sources, 0, 5) as $i => $s) {
            $evidence .= "\n\nSOURCE " . ($i + 1) . ' — ' . ($s['name'] ?? 'source')
                . (filled($s['published_at'] ?? null) ? ', published ' . $s['published_at'] : '')
                . (! empty($s['later']) ? ' [PUBLISHED OR UPDATED AFTER OUR ARTICLE — facts found only here are later developments]' : '')
                . (filled($s['url'] ?? null) ? ' — ' . $s['url'] : '') . "\n"
                . Str::limit(trim(strip_tags((string) $s['text'])), 3500, ' […]');
        }

        $article = trim(preg_replace("/\n{3,}/", "\n\n", strip_tags(str_replace(['</p>', '</h2>'], ["</p>\n\n", "</h2>\n"], $bodyHtml))));
        $kind = $opinion ? 'an OPINION column (judge only its factual claims, not its arguments)' : 'a NEWS article';
        $asOfLine = $asOf
            ? "This is a revision of an article originally published at {$asOf}. The events it describes may have happened "
              . "BEFORE that moment — that is normal and is NOT an error; never flag an event date for being earlier than the "
              . "publication date. Judge facts as the sources report them. A development that the sources date AFTER the "
              . "publication moment may appear only inside a paragraph beginning \"Update (\"; if it appears anywhere else, "
              . "flag it as critical with the fix \"Move into a dated Update paragraph\".\n"
            : '';

        $system = <<<SYS
        You are an independent fact-checker for an American news publication. You did NOT write the
        text below. Check {$kind} against the SOURCE EXCERPTS ONLY — never use your own knowledge to
        accept or reject a claim. {$asOfLine}
        Check every factual claim, especially: names, ages, dates and days of the week, locations, victim
        and injury counts, weapons, arrest and charge details, court rulings, political statements, laws,
        dollar figures, statistics, chronology, attribution, and exact quotations (must be verbatim).
        Also flag: internal contradictions; details no source supports (invented scenes, emotions,
        dialogue, eyewitnesses, interviews); claims of our own reporting or outreach; and, in news,
        editorial opinion presented as reporting.
        Severity:
        - critical: a wrong or unsupported name, number, age, date, weapon, count, charge, ruling or quote;
          a fabricated detail; an internal contradiction about a key fact; misattribution.
        - minor: wording that overstates or understates a supported fact, missing attribution, small
          imprecision.
        - Also minor: vague attribution such as "the sources said", "reports said" or "reports identified" —
          the fix names the specific outlet or official.
        List ONLY problems. Never list a claim the sources support — supported claims are simply not issues.
        If sources genuinely conflict on a fact, list it under source_conflicts. That is NOT an error when the
        article attributes each version or notes the conflict; if the article states one version as settled
        fact, flag it as minor with the fix "attribute each version".
        For every issue give a concrete fix based on the sources, or "" if the sources can't settle it.
        Set sensitive=true when the story involves deaths, injuries, minors, sexual crimes, or criminal
        allegations against named people.
        SYS;

        $user = "HEADLINE: {$title}\n\nARTICLE:\n" . Str::limit($article, 9000, '') . "\n\n=== SOURCE EXCERPTS ==={$evidence}";

        $data = $ai->json('fact_check', $system, $user, [
            'claims_checked' => ['type' => 'integer'],
            'issues' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'claim' => ['type' => 'string'],
                    'problem' => ['type' => 'string'],
                    'severity' => ['type' => 'string', 'enum' => ['critical', 'minor']],
                    'fix' => ['type' => 'string'],
                ],
                'required' => ['claim', 'problem', 'severity', 'fix'],
                'additionalProperties' => false,
            ]],
            'source_conflicts' => ['type' => 'array', 'items' => ['type' => 'string']],
            'unverified' => ['type' => 'array', 'items' => ['type' => 'string']],
            'source_support' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'source' => ['type' => 'integer'],
                    'claims' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['source', 'claims'],
                'additionalProperties' => false,
            ]],
            'sensitive' => ['type' => 'boolean'],
            'summary' => ['type' => 'string'],
        ], Setting::get('fact_check_model') ?: null);

        if (! $data) {
            return $this->result('needs_research', [], 0, [], [], [], false, 'The fact-check could not be completed (AI call failed).');
        }

        $issues = array_values(array_filter((array) ($data['issues'] ?? []), fn ($i) => is_array($i) && filled($i['claim'] ?? null)));
        $critical = array_filter($issues, fn ($i) => ($i['severity'] ?? '') === 'critical');
        $unfixable = array_filter($critical, fn ($i) => blank(trim((string) ($i['fix'] ?? ''))));
        $checked = (int) ($data['claims_checked'] ?? 0);

        // Verdict decided in code from the evidence — never a score that can
        // outweigh a critical factual error.
        $verdict = match (true) {
            $checked === 0 => 'needs_research',
            (bool) $unfixable => 'needs_research',
            (bool) $critical => 'needs_rewrite',
            default => 'ready',
        };

        // Which claims each source supports — stored with the source record.
        $support = [];
        foreach ((array) ($data['source_support'] ?? []) as $row) {
            $idx = (int) ($row['source'] ?? 0) - 1;
            if (isset($sources[$idx]['url'])) {
                $support[$sources[$idx]['url']] = array_values(array_slice((array) ($row['claims'] ?? []), 0, 8));
            }
        }

        return $this->result($verdict, $issues, $checked, (array) ($data['source_conflicts'] ?? []),
            (array) ($data['unverified'] ?? []), $support, (bool) ($data['sensitive'] ?? false), (string) ($data['summary'] ?? ''));
    }

    /**
     * Findings numbered for the revision writer, so every correction it reports
     * can be traced back to (and verified against) a specific finding.
     */
    public static function numberedIssues(array $result): string
    {
        return collect($result['issues'] ?? [])->values()
            ->map(fn ($i, $k) => '#' . ($k + 1) . ' [' . strtoupper($i['severity']) . '] "' . $i['claim'] . '": ' . $i['problem']
                . (filled($i['fix'] ?? null) ? ' → Fix: ' . $i['fix'] : ''))
            ->implode("\n");
    }

    /**
     * Finding numbers (1-based) that may be published as reader-facing
     * corrections: a CRITICAL error in the published version where the sources
     * establish the right version (a non-empty fix). Removing an unsupported
     * detail, tightening wording or removing opinion are edits, not corrections.
     *
     * @return array<int,int>
     */
    public static function correctableIssues(array $result): array
    {
        $out = [];
        foreach (array_values($result['issues'] ?? []) as $k => $i) {
            if (($i['severity'] ?? '') === 'critical' && filled(trim((string) ($i['fix'] ?? '')))) {
                $out[] = $k + 1;
            }
        }

        return $out;
    }

    /** Critical issues formatted as revision feedback for the writer. */
    public static function feedback(array $result): string
    {
        return collect($result['issues'] ?? [])
            ->map(fn ($i) => '- [' . strtoupper($i['severity']) . '] "' . $i['claim'] . '": ' . $i['problem']
                . (filled($i['fix'] ?? null) ? ' → Fix: ' . $i['fix'] : ''))
            ->implode("\n");
    }

    private function result(string $verdict, array $issues, int $checked, array $conflicts, array $unverified, array $support, bool $sensitive, string $summary): array
    {
        return [
            'verdict' => $verdict,
            'claims_checked' => $checked,
            'critical' => count(array_filter($issues, fn ($i) => ($i['severity'] ?? '') === 'critical')),
            'issues' => $issues,
            'source_conflicts' => array_values($conflicts),
            'unverified' => array_values($unverified),
            'source_support' => $support,
            'sensitive' => $sensitive,
            'summary' => $summary,
            'checked_at' => now()->toIso8601String(),
            'model' => Setting::get('fact_check_model') ?: Setting::get('openai_model', config('services.openai.model')),
            'policy_version' => \App\Support\EditorialPolicy::version(),
        ];
    }
}
