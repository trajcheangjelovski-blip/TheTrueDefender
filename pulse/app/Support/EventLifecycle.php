<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Lifecycle awareness for scheduled events (votes, elections, rulings) from the research package's `lifecycle`.
 *
 * A preview or "voting underway" article becomes outdated when the event's results are due. Code compares the
 * package's own dates with the clock; it never invents a result and never rewrites an article: outdated stories are held
 * from publication (FinalCheck) or flagged for an editor (posts:flag-outdated-previews).
 */
class EventLifecycle
{
    /** When the story stops being current: results expected, otherwise the event's scheduled time. */
    public static function endAt(array $package): ?Carbon
    {
        $l = (array) ($package['lifecycle'] ?? []);

        return self::time($l['results_expected_at'] ?? null) ?? self::time($l['scheduled_at'] ?? null);
    }

    public static function startAt(array $package): ?Carbon
    {
        return self::time(((array) ($package['lifecycle'] ?? []))['scheduled_at'] ?? null);
    }

    /** Is a preview / "during the event" story out of date because the event's results are now due? */
    public static function outdated(array $package, ?Carbon $now = null): bool
    {
        $l = (array) ($package['lifecycle'] ?? []);
        if (empty($l['event']) || ! in_array($l['article_type'] ?? '', ['preview'], true) && ! in_array($l['stage'] ?? '', ['preview', 'during'], true)) {
            return false;
        }
        $end = self::endAt($package);

        return $end !== null && ($now ?? now())->gt($end);
    }

    /** Where the event stands now, judged by the package's dates only. */
    public static function stageNow(array $package, ?Carbon $now = null): ?string
    {
        $start = self::startAt($package);
        if (! $start) {
            return null;
        }
        $now ??= now();
        $l = (array) ($package['lifecycle'] ?? []);
        $end = self::time($l['results_expected_at'] ?? null);
        $official = self::time($l['official_results_at'] ?? null);

        return match (true) {
            $now->lt($start) => 'before the event (preview)',
            $official !== null && $now->gte($official) => 'official results due or published (verify them before writing)',
            $end !== null && $now->gt($end) => 'after the event: results are due (verify them; do not describe the event as ongoing)',
            default => 'during the event (results pending)',
        };
    }

    private static function time(mixed $v): ?Carbon
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
