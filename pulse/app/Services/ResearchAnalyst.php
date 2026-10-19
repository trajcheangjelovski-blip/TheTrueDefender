<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use App\Support\AiModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * RESEARCH ANALYSIS (GPT-5.4 by default — AiModels::research()).
 *
 * Brave Search + ArticleFetcher find and read the coverage; this stage turns
 * those evidence packets into a structured RESEARCH PACKAGE before anything is
 * written: which ONE event this is, its dates (event / source publication /
 * update / our discovery / latest development), what is confirmed (each fact
 * tied to packets), quotes, chronology, conflicts, what is unverified, whether
 * it is genuinely new, whether it may be called breaking, and the news angle.
 * It never writes the article and must not use its own knowledge.
 *
 * Code then validates the package deterministically (dates, freshness, event
 * identity) — see assess(). The writer (gpt-5.4-mini) and the fact-checker
 * (gpt-5.4) both receive it.
 */
class ResearchAnalyst
{
    public const UNIT_TYPES = ['people', 'households', 'customers', 'buildings', 'facilities', 'sites', 'missiles', 'drones', 'money', 'energy', 'distance', 'other'];

    public const NEWSWORTHINESS = ['new_event', 'new_development', 'background_explainer', 'republished_old_event', 'not_news'];

    /** Human-interest (Story of Hope): current for this many days, "republished" only after the second number. */
    public const HUMAN_INTEREST_DAYS = 7;

    public const HUMAN_INTEREST_STALE_DAYS = 14;

    public static function isHumanInterest(?string $section): bool
    {
        return $section === 'story-of-hope';
    }

    /**
     * @param array $packets SourcePacket::prepare() output (packet 1 = the story's own source)
     * @return array|null the package, or null when the research model failed (see $error)
     */
    public function analyze(AiClient $ai, Post $post, array $packets, ?string &$error = null): ?array
    {
        $model = AiModels::research();
        if (! AiModels::available($model)) {
            $error = "research model {$model} is not available to this API key";

            return null;
        }

        $tz = config('app.display_timezone', 'America/New_York');
        $now = now($tz);
        $section = (string) ($post->category?->slug ?? '');
        $sectionName = (string) ($post->category?->name ?? 'News');
        $sectionRule = self::isHumanInterest($section)
            ? "\n        SECTION: {$sectionName} — HUMAN-INTEREST, not breaking news. A good story first reported within the last "
              . self::HUMAN_INTEREST_DAYS . " days is current: use new_event (or new_development). Use republished_old_event only "
              . "when the original story is more than " . self::HUMAN_INTEREST_STALE_DAYS . " days old. breaking_eligible is always false. "
              . "An exact calendar date or a statement from the people/organizations involved is NOT required when two "
              . "independent reports agree on the story; set needs_more_research only if the core story itself cannot be confirmed."
            : "\n        SECTION: {$sectionName}.";
        $discovered = ($post->created_at ?? now())->copy()->setTimezone($tz);
        $sourcePub = $post->source_published_at?->copy()->setTimezone($tz);

        $system = <<<SYS
        You are the research desk of an American news publication. You do NOT write articles. You read the
        EVIDENCE PACKETS (live coverage retrieved minutes ago) and produce a structured research package that a
        writer will use. Use ONLY what the packets state — never your own knowledge, never fill a gap with what
        "usually" happens. If something is not in the packets, it is unknown.

        1. EVENT IDENTITY. Identify the ONE event the story is about — the event described by PACKET 1 (the story
           we found). For EVERY packet decide same_event: does it describe this same incident? Compare date,
           place, people, victims, suspects, event type and official investigation. Similar wording is NOT
           sameness: two shootings of National Guard members in Washington, D.C. on different dates are different
           events. A packet about a different incident gets same_event=false and its facts must NOT appear anywhere
           in the package. A packet that covers this event plus older background is same_event=true; its older
           material goes to background with its own date.

        2. DATES — never interchangeable:
           - event_date: when the underlying incident happened (YYYY-MM-DD; "" if no packet establishes it), and
             event_date_basis: which packet/sentence establishes it.
           - each packet's own published / updated time (from its header).
           - latest_development: the newest MATERIAL change in the packets (new announcement, arrest, ruling,
             evacuation change, casualty update…) with its date, YYYY-MM-DD.
           A story published today can describe an incident from last year — that is NOT a new event.

        3. NEWSWORTHINESS — exactly one:
           new_event (happened very recently), new_development (an older story with a genuinely new, dated
           development), background_explainer (no new development; useful only as clearly-labelled background),
           republished_old_event (an old event re-reported as if new, with nothing new), not_news.
           breaking_eligible = true ONLY for a new_event or major new_development from the last few hours.
           Do not manufacture an angle; if nothing is new, say so.

        4. FACTS. confirmed_facts: each established fact with the packet numbers that state it (prefer official /
           primary packets). quotes: verbatim and CONTINUOUS (never join two remarks), with speaker, packet, the occasion (which interview or appearance) and its date YYYY-MM-DD ("" if no packet states it) — a statement made in an earlier interview is not a statement made at a later event. chronology: dated steps in order.
           background: older context, each with its date. conflicts: where packets disagree. unverified: claims a
           single unclear packet makes that should not be stated as fact. missing_evidence: what we would need.
           ongoing = true for developing situations (fires, evacuations, storms, investigations, trials,
           elections, emergencies) whose status can change within hours.

        5. EDITORIAL. angle: the verified reason this deserves coverage today, leading with the latest
           development. reader_questions: what readers will want answered. needs_more_research = true when the
           event, its date or the latest development cannot be established from the packets.

        6. DEVELOPING NEWS (fires, attacks, storms, evacuations, investigations, emergencies): reporting changes through the day.
           - timeline: every dated step with its own kind — event (the underlying incident), official_statement (when an
             official said it), warning and siren (when a warning was received / sirens sounded), development (a material
             change), source_publication and source_update (when a packet was published or revised). Never use a
             publication or update time as the time of the event or statement. at = local date and time as the packets
             give them ("" if unknown).
           - figures: every count or measure. Record WHAT is counted (unit_type: people, households, customers,
             buildings, facilities, sites, missiles, drones, money, energy, distance, other — these are NOT
             interchangeable), the geographic scope, the as_of time or period, who asserted it, whether it is the first
             report, supplements, supersedes or is a different scope/category of an earlier figure, and its status
             (current, superseded, estimate, unverified). Two different figures are NOT a contradiction until you have
             compared scope, time, category and issuer: an earlier count of "more than 10" and a later count of "28" can
             both be right (an earlier estimate, a wider area, a different category). Use relation
             conflicts_with_same_scope ONLY when the same issuer or same scope, time and category give different values.
           - phases: when an attack or event comes in waves or stages, list each phase separately (first wave, second wave…)
             with when it began, and what each official said about THAT phase (warned: yes, no, late — warning arrived too
             late for sirens — or unknown). Reports about different phases are compatible, not contradictory.
           - claims: assertions by governments, militaries, companies or officials (including explanations such as a
             "software problem"): who asserted it and whether an independent source corroborates it. An assertion is
             attributed to its asserter, never stated as an established fact.
           Also put into conflicts only genuine disagreements, with each side attributed.

        Today is {$now->format('l, F j, Y g:i A T')}.{$sectionRule}
        SYS;

        $user = "STORY WE FOUND: {$post->title}\nSOURCE: {$post->source_url}"
            . "\nSource published: " . ($sourcePub ? $sourcePub->format('Y-m-d g:i A T') : 'not stated')
            . "\nWe discovered it: " . $discovered->format('Y-m-d g:i A T')
            . SourcePacket::render($packets);

        $int = ['type' => 'integer'];
        $str = ['type' => 'string'];
        $strs = ['type' => 'array', 'items' => $str];
        $ints = ['type' => 'array', 'items' => $int];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        $data = $ai->json('research', $system, $user, [
            'event' => $obj([
                'title' => $str, 'what_happened' => $str, 'who' => $strs, 'where' => $str, 'event_type' => $str,
                'event_date' => $str, 'event_date_basis' => $str, 'ongoing' => ['type' => 'boolean'],
            ]),
            'latest_development' => $obj(['date' => $str, 'summary' => $str]),
            'newsworthiness' => ['type' => 'string', 'enum' => self::NEWSWORTHINESS],
            'breaking_eligible' => ['type' => 'boolean'],
            'packets' => ['type' => 'array', 'items' => $obj([
                'packet' => $int, 'same_event' => ['type' => 'boolean'], 'published' => $str, 'updated' => $str, 'note' => $str,
            ])],
            'confirmed_facts' => ['type' => 'array', 'items' => $obj(['fact' => $str, 'packets' => $ints])],
            'quotes' => ['type' => 'array', 'items' => $obj(['quote' => $str, 'speaker' => $str, 'packet' => $int, 'occasion' => $str, 'date' => $str])],
            'chronology' => ['type' => 'array', 'items' => $obj(['date' => $str, 'event' => $str, 'packets' => $ints])],
            'background' => ['type' => 'array', 'items' => $obj(['fact' => $str, 'packets' => $ints])],
            'timeline' => ['type' => 'array', 'items' => $obj([
                'kind' => ['type' => 'string', 'enum' => ['event', 'official_statement', 'warning', 'siren', 'development', 'source_publication', 'source_update']],
                'at' => $str, 'what' => $str, 'packets' => $ints,
            ])],
            'figures' => ['type' => 'array', 'items' => $obj([
                'label' => $str, 'value' => $str, 'number' => $str,
                'unit_type' => ['type' => 'string', 'enum' => self::UNIT_TYPES],
                'scope' => $str, 'as_of' => $str, 'asserted_by' => $str, 'packets' => $ints,
                'status' => ['type' => 'string', 'enum' => ['current', 'superseded', 'estimate', 'unverified']],
                'relation' => ['type' => 'string', 'enum' => ['first_report', 'supplements', 'supersedes', 'different_scope', 'different_category', 'conflicts_with_same_scope']],
                'note' => $str,
            ])],
            'phases' => ['type' => 'array', 'items' => $obj([
                'label' => $str, 'order' => $int, 'began' => $str, 'note' => $str,
                'statements' => ['type' => 'array', 'items' => $obj([
                    'said_by' => $str, 'statement' => $str, 'packet' => $int,
                    'warned' => ['type' => 'string', 'enum' => ['yes', 'no', 'late', 'unknown']],
                ])],
            ])],
            'claims' => ['type' => 'array', 'items' => $obj([
                'claim' => $str, 'asserted_by' => $str, 'corroborated_independently' => ['type' => 'boolean'], 'packets' => $ints,
            ])],
            'conflicts' => $strs,
            'unverified' => $strs,
            'missing_evidence' => $strs,
            'angle' => $str,
            'reader_questions' => $strs,
            'needs_more_research' => ['type' => 'boolean'],
        ], $model, 240, AiModels::researchEffort());

        if (! $data) {
            $error = 'research model failed: ' . ($ai->lastError() ?? 'no usable output');

            return null;
        }

        $data['event_key'] = self::eventKey($data['event'] ?? []);
        $data['section'] = $section;
        $data['model'] = $model;
        $data['researched_at'] = now()->toIso8601String();
        $data['source_hash'] = self::sourceHash($packets);
        $data['packet_urls'] = array_values(array_map(fn ($p) => (string) ($p['url'] ?? ''), $packets));

        return $data;
    }

    /**
     * Deterministic checks on a package (no AI): which packets to keep and
     * whether the story may proceed, and how.
     *
     * @return array{keep:array<int,int>, hold:?string, reject:?string, breaking:bool, ongoing:bool}
     */
    public static function assess(array $package): array
    {
        $keep = [];
        foreach ((array) ($package['packets'] ?? []) as $p) {
            if (! empty($p['same_event'])) {
                $keep[] = (int) $p['packet'];
            }
        }
        $verifyIdentity = self::on('research_require_source_identity');
        if (! $verifyIdentity) {
            $keep = range(1, max(1, count((array) ($package['packet_urls'] ?? []))));
        }

        $news = (string) ($package['newsworthiness'] ?? '');
        $event = self::date($package['event']['event_date'] ?? null);
        $latest = self::date($package['latest_development']['date'] ?? null);
        $human = self::isHumanInterest($package['section'] ?? null);
        // Human-interest (Story of Hope) isn't breaking news: a story from the last
        // week is current, and it is only "republished" once it is over two weeks old.
        $maxDevDays = $human
            ? max(1, (int) Setting::get('max_development_age_days_hope', self::HUMAN_INTEREST_DAYS))
            : max(1, (int) Setting::get('max_development_age_days', 2));
        $recent = $latest ?? $event;
        if ($human && $news === 'republished_old_event' && $recent && $recent->gte(now()->subDays(self::HUMAN_INTEREST_STALE_DAYS)->startOfDay())) {
            $news = 'new_event';
        }

        $reject = match (true) {
            $news === 'republished_old_event' => 'Republished old event with no new development (event ' . ($event?->toDateString() ?? 'date unknown') . ').',
            $news === 'not_news' => 'The research found nothing newsworthy.',
            default => null,
        };

        $hold = match (true) {
            $reject !== null => null,
            ! in_array(1, $keep, true) => 'Research could not confirm the story\'s own source describes the identified event.',
            // Human-interest: two agreeing independent reports + a dated latest development
            // are enough — a missing exact date or primary statement doesn't hold it.
            ! empty($package['needs_more_research']) && ! ($human && $latest && count($keep) >= 2) => 'Research could not establish the event, its date or the latest development: '
                . implode('; ', array_slice((array) ($package['missing_evidence'] ?? []), 0, 3)),
            // Human-interest stories often give no exact date ("on a Monday"); a dated
            // latest development is enough for them. News must have an event date.
            self::on('research_require_event_date') && ! $event && ! ($human && $latest) => 'No source establishes when the event happened.',
            in_array($news, ['new_event', 'new_development'], true) && self::on('research_check_latest_development')
                && (! $recent || $recent->lt(now()->subDays($maxDevDays)->startOfDay())) => 'The latest verified development ('
                . ($recent?->toDateString() ?? 'undated') . ") is older than {$maxDevDays} day(s) — not current news.",
            default => null,
        };

        // A misleading "breaking" label is removed, never published.
        $breaking = ! $human && ! empty($package['breaking_eligible']) && in_array($news, ['new_event', 'new_development'], true)
            && $latest && $latest->gte(now()->subHours(12)->startOfDay());

        return [
            'keep' => $keep,
            'hold' => $hold,
            'reject' => $reject,
            'breaking' => $breaking,
            'ongoing' => ! empty($package['event']['ongoing']),
        ];
    }

    /** "2026-10-19|washington-dc|shooting" — the event's identity for duplicate detection. */
    public static function eventKey(array $event): string
    {
        $date = self::date($event['event_date'] ?? null)?->toDateString() ?? 'undated';
        $place = Str::slug(Str::limit((string) ($event['where'] ?? ''), 40, ''));
        $type = Str::slug(Str::limit((string) ($event['event_type'] ?? ''), 30, ''));

        return "{$date}|{$place}|{$type}";
    }

    public static function sourceHash(array $packets): string
    {
        return md5(collect($packets)->map(fn ($p) => ($p['url'] ?? '') . ':' . strlen((string) ($p['text'] ?? '')))->sort()->implode('|'));
    }

    /** Short identity block for the fact-checker. */
    public static function identityBlock(array $p): string
    {
        $e = (array) ($p['event'] ?? []);

        return 'Event: ' . ($e['title'] ?? '') . "\nWhat happened: " . ($e['what_happened'] ?? '')
            . "\nWhere: " . ($e['where'] ?? '') . "\nWho: " . implode('; ', (array) ($e['who'] ?? []))
            . "\nEvent date: " . (($e['event_date'] ?? '') ?: 'not established')
            . "\nLatest development (" . (($p['latest_development']['date'] ?? '') ?: 'undated') . '): ' . ($p['latest_development']['summary'] ?? '')
            . "\nNewsworthiness: " . ($p['newsworthiness'] ?? '');
    }

    /** The verified package as the writer sees it (facts carry their packet numbers). */
    public static function writerBlock(array $p): string
    {
        $lines = fn (array $rows, callable $fmt) => collect($rows)->map($fmt)->filter()->implode("\n");
        $pk = fn ($ids) => $ids ? ' [packet ' . implode(', ', (array) $ids) . ']' : '';

        return self::identityBlock($p)
            . "\nNews angle: " . ($p['angle'] ?? '')
            . "\n\nCONFIRMED FACTS (state only these as fact):\n" . $lines((array) ($p['confirmed_facts'] ?? []), fn ($f) => '- ' . ($f['fact'] ?? '') . $pk($f['packets'] ?? []))
            . "\n\nQUOTES (verbatim):\n" . $lines((array) ($p['quotes'] ?? []), fn ($q) => '- "' . ($q['quote'] ?? '') . '" — ' . ($q['speaker'] ?? '')
                . (filled($q['occasion'] ?? null) || filled($q['date'] ?? null) ? ' (' . trim(($q['occasion'] ?? '') . ' ' . ($q['date'] ?? '')) . ')' : '') . $pk([$q['packet'] ?? null]))
            . "\n\nCHRONOLOGY:\n" . $lines((array) ($p['chronology'] ?? []), fn ($c) => '- ' . ($c['date'] ?? '') . ': ' . ($c['event'] ?? '') . $pk($c['packets'] ?? []))
            . "\n\nBACKGROUND (older — always give its date, never present as new):\n" . $lines((array) ($p['background'] ?? []), fn ($b) => '- ' . ($b['fact'] ?? '') . $pk($b['packets'] ?? []))
            . "\n\nCONFLICTS (attribute each version):\n" . $lines((array) ($p['conflicts'] ?? []), fn ($c) => '- ' . $c)
            . "\n\nUNVERIFIED (do NOT state as fact; omit or attribute exactly):\n" . $lines((array) ($p['unverified'] ?? []), fn ($c) => '- ' . $c)
            . self::developingBlock($p);
    }

    /**
     * Timeline, figures, phases and official claims of a developing story — for the writer and the fact-checker.
     * Empty for packages without them (older records, simple stories).
     */
    public static function developingBlock(array $p): string
    {
        $out = '';
        $pk = fn ($ids) => $ids ? ' [packet ' . implode(', ', (array) $ids) . ']' : '';
        if (! empty($p['timeline'])) {
            $out .= "\n\nTIMELINE (an event time, a statement time and a publication time are different things):\n"
                . collect($p['timeline'])->map(fn ($t) => '- [' . ($t['kind'] ?? '') . '] ' . (($t['at'] ?? '') ?: 'time unknown') . ': ' . ($t['what'] ?? '') . $pk($t['packets'] ?? []))->implode("\n");
        }
        if (! empty($p['figures'])) {
            $out .= "\n\nFIGURES (compare scope, time and category before calling two figures a conflict; people, households, customers, buildings, facilities and sites are NOT interchangeable; state a superseded figure only as an earlier one):\n"
                . collect($p['figures'])->map(fn ($f) => '- ' . ($f['label'] ?? '') . ': ' . ($f['value'] ?? '') . ' [' . ($f['unit_type'] ?? '') . '; scope: ' . (($f['scope'] ?? '') ?: 'not stated')
                    . '; as of ' . (($f['as_of'] ?? '') ?: 'time not stated') . '; asserted by ' . (($f['asserted_by'] ?? '') ?: 'unclear') . '; ' . ($f['status'] ?? '') . '; ' . ($f['relation'] ?? '') . ']'
                    . (filled($f['note'] ?? null) ? ' ' . $f['note'] : '') . $pk($f['packets'] ?? []))->implode("\n");
            $notes = self::figureNotes($p);
            if ($notes) {
                $out .= "\nHOW THE FIGURES RELATE (computed): " . collect($notes)->map(fn ($n) => $n['why'])->implode(' | ');
            }
        }
        if (! empty($p['phases'])) {
            $out .= "\n\nPHASES / WAVES (statements about different phases are compatible; report each phase on its own terms):\n"
                . collect($p['phases'])->sortBy('order')->map(function ($ph) use ($pk) {
                    $lines = collect($ph['statements'] ?? [])->map(fn ($s) => '    · ' . ($s['said_by'] ?? '') . ' [' . ($s['warned'] ?? 'unknown') . ']: ' . ($s['statement'] ?? '') . $pk([$s['packet'] ?? null]))->implode("\n");

                    return '- ' . ($ph['label'] ?? '') . (filled($ph['began'] ?? null) ? ' (began ' . $ph['began'] . ')' : '') . (filled($ph['note'] ?? null) ? ': ' . $ph['note'] : '') . ($lines !== '' ? "\n" . $lines : '');
                })->implode("\n");
            foreach (self::phaseConflicts($p) as $c) {
                $out .= "\nGENUINE DISAGREEMENT in " . $c['phase'] . ': ' . $c['why'];
            }
        }
        if (! empty($p['claims'])) {
            $out .= "\n\nOFFICIAL CLAIMS (attribute to the asserter; not established fact unless independently corroborated):\n"
                . collect($p['claims'])->map(fn ($c) => '- ' . ($c['asserted_by'] ?? '') . ': ' . ($c['claim'] ?? '') . (! empty($c['corroborated_independently']) ? ' [independently corroborated]' : ' [not independently corroborated]') . $pk($c['packets'] ?? []))->implode("\n");
        }

        return $out;
    }

    /**
     * Deterministic reading of the package's figures: same unit_type pairs are compared by scope, time and issuer.
     * Different scope or category = compatible; same scope, later time = supersedes; same scope, same time and
     * different values = genuine conflict. Unknown times are never called a conflict.
     *
     * @return array<int,array{status:string,why:string,a:array,b:array}>
     */
    public static function figureNotes(array $package): array
    {
        $figs = array_values((array) ($package['figures'] ?? []));
        $out = [];
        for ($i = 0; $i < count($figs); $i++) {
            for ($j = $i + 1; $j < count($figs); $j++) {
                [$a, $b] = [$figs[$i], $figs[$j]];
                $na = self::num($a['number'] ?? ($a['value'] ?? ''));
                $nb = self::num($b['number'] ?? ($b['value'] ?? ''));
                if (($a['unit_type'] ?? '') !== ($b['unit_type'] ?? '') || $na === null || $nb === null || $na == $nb) {
                    continue;
                }
                $sa = self::norm($a['scope'] ?? '');
                $sb = self::norm($b['scope'] ?? '');
                $ta = self::norm($a['as_of'] ?? '');
                $tb = self::norm($b['as_of'] ?? '');
                $label = ($a['value'] ?? '') . ' and ' . ($b['value'] ?? '');
                if ($sa !== '' && $sb !== '' && $sa !== $sb) {
                    $out[] = ['status' => 'compatible', 'a' => $a, 'b' => $b, 'why' => "{$label} cover different areas ({$a['scope']} / {$b['scope']}): compatible"];
                } elseif ($ta !== '' && $tb !== '' && $ta !== $tb) {
                    $out[] = ['status' => 'supersedes', 'a' => $a, 'b' => $b, 'why' => "{$label} are from different times ({$a['as_of']} / {$b['as_of']}): the later one supersedes or supplements the earlier, not a contradiction"];
                } elseif ($ta === '' || $tb === '') {
                    $out[] = ['status' => 'unclear', 'a' => $a, 'b' => $b, 'why' => "{$label}: time of at least one figure not stated; do not call them contradictory, attribute each"];
                } else {
                    $out[] = ['status' => 'conflict', 'a' => $a, 'b' => $b, 'why' => "{$label} describe the same scope and time but differ (" . ($a['asserted_by'] ?? '?') . ' / ' . ($b['asserted_by'] ?? '?') . '): genuine disagreement, attribute each'];
                }
            }
        }

        return $out;
    }

    /**
     * Within ONE phase of an attack, a source saying "warned: yes" and another "warned: no" genuinely conflict.
     * "late" (a warning that came too late for sirens) is compatible with "no warning of the first wave" and with a
     * later wave having been warned; different phases never conflict.
     *
     * @return array<int,array{phase:string,why:string}>
     */
    public static function phaseConflicts(array $package): array
    {
        $out = [];
        foreach ((array) ($package['phases'] ?? []) as $ph) {
            $yes = collect($ph['statements'] ?? [])->where('warned', 'yes')->pluck('said_by')->all();
            $no = collect($ph['statements'] ?? [])->where('warned', 'no')->pluck('said_by')->all();
            if ($yes && $no) {
                $out[] = ['phase' => (string) ($ph['label'] ?? 'a phase'), 'why' => implode(', ', $yes) . ' say a warning was received; ' . implode(', ', $no) . ' say none was. Attribute both.'];
            }
        }

        return $out;
    }

    private static function num(mixed $v): ?float
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (preg_match('/half\s+a?\s*-?\s*million|half-million/', $v)) {
            return 500000.0;
        }
        if (preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(thousand|million|billion)?/', $v, $m)) {
            $n = (float) str_replace(',', '', $m[1]);

            return $n * (['thousand' => 1e3, 'million' => 1e6, 'billion' => 1e9][$m[2] ?? ''] ?? 1);
        }

        return null;
    }

    private static function norm(mixed $v): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $v)));
    }

    private static function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse((string) $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function on(string $key): bool
    {
        return (bool) (int) Setting::get($key, '1');
    }
}
