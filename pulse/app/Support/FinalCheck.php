<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;
use App\Services\ResearchAnalyst;
use App\Services\WebResearcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The last gate before a NEWS story goes live (promotion and the breaking
 * lane both call it, after PublishGate). It answers, from the stored research
 * package and — for developing stories — a live re-check:
 *
 *   1. Was the story researched, and is the event identified?      (package present)
 *   2-3. Is the event date known and do the sources describe it?     (ResearchAnalyst::assess)
 *   4-5. Is the main development still current / not superseded?    (age + live newer-coverage check)
 *   6. Is a "breaking" label supported?                              (assess → breaking)
 *   7-9. Unsupported claims, fact-check, formatting                 (PublishGate, already run)
 *
 * Never publishes just because the writer finished. Opinion columns and manual
 * posts (no source_url) are not news-researched and are not judged here.
 *
 * @return array<string,string> reason-key => message; [] = may publish
 */
class FinalCheck
{
    /** A developing story's research older than this is re-checked live before publishing. */
    public const RECHECK_AFTER_MINUTES = 45;

    public static function failures(Post $post): array
    {
        if (PublishGate::isOpinion($post) || blank($post->source_url)) {
            return [];
        }

        $package = (array) ($post->research_package ?? []);
        if (empty($package['event'])) {
            // A draft written before the research stage existed (or whose research
            // never ran): send it through research once instead of leaving it stuck.
            if ($post->status === 'draft' && $post->queued_at && ! $package && Cache::add("final-research:{$post->id}", 1, now()->addDay())) {
                $post->forceFill(['web_researched_at' => null])->saveQuietly();
            }

            return ['not-researched' => 'No research package: every news story is researched before it is written. '
                . ($package['hold_reason'] ?? $package['error'] ?? 'Sent back through research.')];
        }

        $fails = [];
        $check = ResearchAnalyst::assess($package);
        if ($check['reject'] || $check['hold']) {
            $fails['research'] = (string) ($check['reject'] ?? $check['hold']);
        }
        if ($post->is_breaking && ! $check['breaking'] && (bool) (int) Setting::get('block_misleading_breaking', '1')) {
            $fails['misleading-breaking'] = 'Labelled breaking, but the research found no recent development that justifies it.';
        }

        // Developing stories: has anything newer appeared since we researched it?
        $researched = filled($package['researched_at'] ?? null) ? Carbon::parse($package['researched_at']) : null;
        if (! $fails && $check['ongoing'] && $researched && (bool) (int) Setting::get('research_recheck_developing', '1')
            && $researched->lt(now()->subMinutes(self::RECHECK_AFTER_MINUTES))) {
            $hosts = array_values(array_filter(array_map(
                fn ($u) => strtolower(preg_replace('/^www\./', '', (string) parse_url((string) $u, PHP_URL_HOST))),
                array_merge([$post->source_url], array_column((array) $post->sources, 'url')),
            )));
            $newer = app(WebResearcher::class)->newerCoverage((string) $post->title, $hosts, $researched);
            if ($newer) {
                // Send it back through research (bounded: at most twice per story).
                $n = (int) Cache::get("final-recheck:{$post->id}", 0);
                Cache::put("final-recheck:{$post->id}", $n + 1, now()->addDay());
                if ($n < 2) {
                    $post->forceFill(['web_researched_at' => null])->saveQuietly();
                }
                $fails['superseded'] = 'Newer coverage appeared after our research (' . implode(', ', array_column($newer, 'name'))
                    . ($n < 2 ? ') — re-researching before publication.' : ') — held for an editor.');
            }
        }

        return $fails;
    }
}
