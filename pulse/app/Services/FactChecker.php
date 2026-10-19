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
    public function check(AiClient $ai, string $title, string $bodyHtml, array $sources, ?string $asOf = null, bool $opinion = false, ?array $research = null): array
    {
        // Independent check on its OWN model (default gpt-5.4) — never the writer's.
        // An unavailable model holds the article; it is never "passed" by fallback.
        $model = \App\Support\AiModels::factCheck();
        if (! \App\Support\AiModels::available($model)) {
            return ['error' => "fact-check model {$model} is not available to this API key"]
                + $this->result('needs_research', [], 0, [], [], [], false, "Held for review: the fact-check model {$model} is not available.");
        }

        $sources = array_values(array_filter($sources, fn ($s) => str_word_count((string) ($s['text'] ?? '')) >= 60));
        if (! $sources) {
            return $this->result('needs_research', [], 0, [], [], [], false, 'No source evidence was available to verify this article.');
        }

        $evidence = '';
        foreach (array_slice($sources, 0, 5) as $i => $s) {
            $evidence .= "\n\nSOURCE " . ($i + 1) . ' — ' . ($s['name'] ?? 'source')
                . (filled($s['published_at'] ?? null) ? ', published ' . $s['published_at'] : '')
                . (! empty($s['later']) ? ' [PUBLISHED OR UPDATED AFTER OUR ARTICLE — facts found only here are later developments]' : '')
                . (filled($s['url'] ?? null) ? ' — ' . $s['url'] : '')
                . SourcePacket::relationLine($s) . "\n"
                // Same excerpt lengths the writer saw (Rewriter::writeArticle), so the
                // checker never judges against less evidence than the writer had.
                . Str::limit(trim(strip_tags((string) $s['text'])), $i === 0 ? 5500 : 3500, ' […]');
        }

        $article = trim(preg_replace("/\n{3,}/", "\n\n", strip_tags(str_replace(['</p>', '</h2>'], ["</p>\n\n", "</h2>\n"], $bodyHtml))));
        $kind = $opinion ? 'an OPINION column (judge only its factual claims, not its arguments)' : 'a NEWS article';
        $asOfLine = $asOf
            ? "This is a revision of an article originally published at {$asOf}. The events it describes may have happened "
              . "BEFORE that moment — that is normal and is NOT an error; never flag an event date for being earlier than the "
              . "publication date. Judge facts as the sources report them. A fact counts as a LATER development only if it "
              . "appears solely in sources marked as published or updated after our article — if any other source also "
              . "has it, it was known in time and is fine in the body. A later development may appear only inside a "
              . "paragraph beginning \"Update (\"; if one appears anywhere else, flag it as MINOR (an editor reviews every "
              . "revision) with the fix \"Move into the dated Update note\".\n"
            : '';

        // Event identity + freshness from the research stage (ResearchAnalyst).
        $identityLine = $research
            ? "A RESEARCH PACKAGE identifies the ONE event this article must cover (its date, place, people and latest "
              . "development). Flag as CRITICAL: any victim, suspect, date, place or circumstance that belongs to a "
              . "DIFFERENT incident (fix: \"Remove the details of the other incident: …\"); and a headline or lead that "
              . "presents an older event or development as new when the package's latest development is something else "
              . "(fix: lead with the latest development). The package only identifies the event — verify every fact "
              . "against the SOURCE EXCERPTS.\n"
            : '';

        $system = <<<SYS
        You are an independent fact-checker for an American news publication. You did NOT write the
        text below. Check {$kind} against the SOURCE EXCERPTS ONLY — never use your own knowledge to
        accept or reject a claim. {$asOfLine}{$identityLine}
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
        Different wordings of the SAME fact are consistent, not errors: "four people were killed" and "three
        children and a teacher were killed" describe the same victims; "about 570 acres" and "570 acres" agree.
        Do not flag word choice or paraphrase as critical when the fact itself matches the sources.
        Before flagging missing attribution, reread the exact sentence and its connected passage.
        If the article already names the relevant official, document or outlet there, do NOT claim it
        is missing or demand that the name be repeated in every sentence. Quote the actual offending
        words in claim, not a paraphrase that omits attribution already present in the article.
        An outlet named at the start of one connected passage can support the sentences that follow;
        request a new attribution only when the source changes or the reader could mistake who made a claim.
        Accurate paraphrases need not use the source's identical wording; exact wording is required
        only inside quotation marks. Do not manufacture an issue to fill the issues array.
        Distinguish dated updates and geographic scope from contradictions. A later acreage count
        can supersede an earlier one, and a specific locality can lie within a broader named region.
        Check what the sources actually establish; flag misleading chronology or scope when present,
        but do not declare those descriptions conflicting merely because their wording differs.
        Track each named person together with the exact role, party, office, district and opponent shown
        in the source. Similar names are not interchangeable. Never infer one candidate's race from
        nearby facts about another candidate. Never expand a surname to a first and last name unless
        that full name appears in the supplied excerpts. If the research only identifies someone by
        surname, keep that name form or omit it. Read the full connected passage before identifying a
        race: when the article clearly states which contest is for Senate and later describes a separate
        House seat opened by that Senate bid, do not invent a contradiction or claim the article has
        assigned the wrong race. Flag a candidate/race error as critical only when the text directly
        assigns a person to a different race or the evidence clearly establishes the mismatch. If wording
        is genuinely ambiguous to readers, describe that ambiguity as minor and suggest a narrow
        clarification. Do not treat omitted biographical detail as an error unless it changes a material
        fact. A concise omission is better than a guessed identity.
        When a source says officials have not publicly established a connection, the article may report
        exactly that. Do not call the qualification unverified or infer that the investigations are
        connected. Do not flag a correct, verbatim excerpt from the source as an altered quotation.
        Minor findings must describe an actual factual imprecision or real attribution ambiguity, not
        a preference for closer paraphrase when the article preserves the same fact and qualification.
        If sources genuinely conflict on a fact, list it under source_conflicts. That is NOT an error when the
        article attributes each version or notes the conflict; if the article states one version as settled
        fact, flag it as minor with the fix "attribute each version".
        For every issue give a concrete fix based on the sources, or "" if the sources can't settle it.
        ORIGINAL REPORTING AND INDEPENDENCE: a source marked DERIVED or AGGREGATOR repeats another outlet's
        reporting. It does not independently confirm that outlet's facts; flag as minor an article that treats
        repeated coverage of one interview as several confirmations, and as critical an interview, quotation or
        statement credited to the wrong outlet or person.
        QUOTATIONS: every direct quotation must be one continuous verbatim passage by the person it is attributed
        to. Flag as critical a quotation that joins separate remarks (even from the same interview), changes words,
        drops words that change the meaning, or credits remarks to a different occasion than the source does.
        STATEMENT CHRONOLOGY: keep each statement with the occasion and date the source gives (an earlier interview
        versus a later press appearance). Flag as critical an article that presents an earlier remark as made at a
        later event, or a source's publication date as the date of the event or statement.
        NUMBERS AND UNITS: compare every quantity with its unit and the transaction or period it describes.
        Gallons, barrels, liters and metric tons are different measures and must never be converted by the writer.
        Flag as critical any figure the sources do not state, a changed unit, or two different figures presented as the
        same quantity; ignore rounding differences under about 1%.
        OPENING AND ENDING: flag as minor an excerpt that repeats the lead's sentences, a lead that restates the
        headline instead of the central verified development, and an ending on incidental background (for example
        how long a venue has operated) when a verified follow-up or latest development is available.
        Set sensitive=true when the story involves deaths, injuries, minors, sexual crimes, or criminal
        allegations against named people.
        SYS;

        $user = "HEADLINE: {$title}\n\nARTICLE:\n" . Str::limit($article, 9000, '')
            . ($research ? "\n\n=== RESEARCH PACKAGE (event identity) ===\n" . \App\Services\ResearchAnalyst::identityBlock($research) : '')
            . "\n\n=== SOURCE EXCERPTS ==={$evidence}";

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
        ], $model, 240, \App\Support\AiModels::factCheckEffort());

        if (! $data) {
            // Never assume a pass: the article stays held and the reason is recorded.
            return ['error' => (string) ($ai->lastError() ?? 'AI call failed')]
                + $this->result('needs_research', [], 0, [], [], [], false, 'The fact-check could not be completed (AI call failed).');
        }

        $issues = array_values(array_filter((array) ($data['issues'] ?? []), fn ($i) => is_array($i) && filled($i['claim'] ?? null)));
        $issues = array_merge($issues, \App\Support\ExactQuoteVerifier::findings($bodyHtml, $sources, $issues));
        $issues = array_merge($issues, \App\Support\NamedEntityVerifier::findings($bodyHtml, $sources, $issues));
        $issues = array_merge($issues, \App\Support\NumberUnitCheck::findings($bodyHtml, $sources));
        // A "finding" with no real explanation (e.g. problem = "critical") is
        // malformed model output: keep it visible to the editor, but as minor —
        // a block must rest on a stated reason.
        foreach ($issues as $k => $i) {
            $why = trim((string) ($i['problem'] ?? ''));
            // Only an empty reason or a bare label is malformed — "source says 43" is a real reason.
            if (mb_strlen($why) < 4 || in_array(mb_strtolower(rtrim($why, '.')), ['critical', 'minor', 'error', 'wrong', 'unsupported', 'incorrect', 'false'], true)) {
                $issues[$k]['severity'] = 'minor';
                $issues[$k]['problem'] = $why === '' ? '(no reason given by the checker)' : $why . ' (no reason given by the checker)';
            }
        }
        $critical = array_filter($issues, fn ($i) => ($i['severity'] ?? '') === 'critical');
        $unfixable = array_filter($critical, fn ($i) => blank(trim((string) ($i['fix'] ?? ''))));
        $checked = (int) ($data['claims_checked'] ?? 0);
        $unverified = array_values(array_filter((array) ($data['unverified'] ?? []), fn ($claim) => filled($claim)));

        // Verdict decided in code from the evidence — never a score that can
        // outweigh a critical factual error.
        $verdict = match (true) {
            $checked === 0 => 'needs_research',
            (bool) $unverified => 'needs_research',
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
            $unverified, $support, (bool) ($data['sensitive'] ?? false), (string) ($data['summary'] ?? ''));
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
            $fix = trim((string) ($i['fix'] ?? ''));
            // A fix that only removes the claim ("Remove this claim unless it can be
            // sourced") means the sources don't settle it — that's an edit, not a
            // correction of an established fact.
            $removal = (bool) preg_match('/^(remove|delete|omit|drop|cut|strike)\b|unless (it|this) can be sourced/i', $fix);
            if (($i['severity'] ?? '') === 'critical' && $fix !== '' && ! $removal) {
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

    /**
     * Is "needs_research" here ONLY because of unverified claims? (Claims were
     * checked and no critical error lacks a fix.) Then the writer can resolve it
     * by removing or attributing those claims — see EditorialPipeline::checkAndFix.
     */
    public static function onlyUnverified(array $result): bool
    {
        $unfixable = array_filter((array) ($result['issues'] ?? []), fn ($i) => ($i['severity'] ?? '') === 'critical' && blank(trim((string) ($i['fix'] ?? ''))));

        return ($result['verdict'] ?? null) === 'needs_research'
            && (int) ($result['claims_checked'] ?? 0) > 0
            && ! empty($result['unverified'])
            && $unfixable === [];
    }

    /** Writer instructions for claims the sources don't establish as written. */
    public static function unverifiedFeedback(array $result): string
    {
        return collect($result['unverified'] ?? [])
            ->map(fn ($claim) => '- [UNVERIFIED — remove or attribute] "' . trim((string) $claim) . '": the sources do not establish this '
                . 'as written. Remove it, or state it only exactly as a named source states it (with that source\'s link).')
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
            'model' => \App\Support\AiModels::factCheck(),
            'policy_version' => \App\Support\EditorialPolicy::version(),
        ];
    }
}
