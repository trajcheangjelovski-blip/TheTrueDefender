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
    public const NEWSWORTHINESS = ['new_event', 'new_development', 'background_explainer', 'republished_old_event', 'not_news'];

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
           primary packets). quotes: verbatim, with speaker and packet. chronology: dated steps in order.
           background: older context, each with its date. conflicts: where packets disagree. unverified: claims a
           single unclear packet makes that should not be stated as fact. missing_evidence: what we would need.
           ongoing = true for developing situations (fires, evacuations, storms, investigations, trials,
           elections, emergencies) whose status can change within hours.

        5. EDITORIAL. angle: the verified reason this deserves coverage today, leading with the latest
           development. reader_questions: what readers will want answered. needs_more_research = true when the
           event, its date or the latest development cannot be established from the packets.

        Today is {$now->format('l, F j, Y g:i A T')}.
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
            'quotes' => ['type' => 'array', 'items' => $obj(['quote' => $str, 'speaker' => $str, 'packet' => $int])],
            'chronology' => ['type' => 'array', 'items' => $obj(['date' => $str, 'event' => $str, 'packets' => $ints])],
            'background' => ['type' => 'array', 'items' => $obj(['fact' => $str, 'packets' => $ints])],
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
        $event = self::date($package['event']['date'] ?? $package['event']['event_date'] ?? null);
        $latest = self::date($package['latest_development']['date'] ?? null);
        $maxDevDays = max(1, (int) Setting::get('max_development_age_days', 2));

        $reject = match (true) {
            $news === 'republished_old_event' => 'Republished old event with no new development (event ' . ($event?->toDateString() ?? 'date unknown') . ').',
            $news === 'not_news' => 'The research found nothing newsworthy.',
            default => null,
        };

        $hold = match (true) {
            $reject !== null => null,
            ! in_array(1, $keep, true) => 'Research could not confirm the story\'s own source describes the identified event.',
            ! empty($package['needs_more_research']) => 'Research could not establish the event, its date or the latest development: '
                . implode('; ', array_slice((array) ($package['missing_evidence'] ?? []), 0, 3)),
            self::on('research_require_event_date') && ! $event => 'No source establishes when the event happened.',
            in_array($news, ['new_event', 'new_development'], true) && self::on('research_check_latest_development')
                && (! $latest || $latest->lt(now()->subDays($maxDevDays)->startOfDay())) => 'The latest verified development ('
                . ($latest?->toDateString() ?? 'undated') . ") is older than {$maxDevDays} day(s) — not current news.",
            default => null,
        };

        // A misleading "breaking" label is removed, never published.
        $breaking = ! empty($package['breaking_eligible']) && in_array($news, ['new_event', 'new_development'], true)
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
            . "\n\nQUOTES (verbatim):\n" . $lines((array) ($p['quotes'] ?? []), fn ($q) => '- "' . ($q['quote'] ?? '') . '" — ' . ($q['speaker'] ?? '') . $pk([$q['packet'] ?? null]))
            . "\n\nCHRONOLOGY:\n" . $lines((array) ($p['chronology'] ?? []), fn ($c) => '- ' . ($c['date'] ?? '') . ': ' . ($c['event'] ?? '') . $pk($c['packets'] ?? []))
            . "\n\nBACKGROUND (older — always give its date, never present as new):\n" . $lines((array) ($p['background'] ?? []), fn ($b) => '- ' . ($b['fact'] ?? '') . $pk($b['packets'] ?? []))
            . "\n\nCONFLICTS (attribute each version):\n" . $lines((array) ($p['conflicts'] ?? []), fn ($c) => '- ' . $c)
            . "\n\nUNVERIFIED (do NOT state as fact; omit or attribute exactly):\n" . $lines((array) ($p['unverified'] ?? []), fn ($c) => '- ' . $c);
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
