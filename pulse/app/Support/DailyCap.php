<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;

/**
 * The hard daily publishing ceiling, in one place. EVERY path that publishes a
 * post (queue promotion, draft recovery, opinion columns, immediate ingest)
 * checks the same count, so "10 a day" means 10 total — not 10 per path.
 *
 * Counts ALL posts published today, regardless of source, so opinion columns
 * and manually-published posts also count toward the ceiling.
 */
class DailyCap
{
    public static function cap(): int
    {
        return (int) Setting::get('daily_publish_cap', 0);
    }

    /**
     * How many posts have gone live today (every category and source).
     *
     * "Today" is the New York editorial day (config('app.display_timezone')),
     * the site's official US timezone — NOT the UTC day. Counting by the UTC
     * day split one US calendar day across two cap windows (the boundary falls
     * ~01:00-02:00 US Eastern), which let up to 2x the cap publish in a single
     * editorial day. Timestamps are stored in UTC, so the New York day is
     * converted to a UTC [start, end] range for the comparison.
     */
    public static function publishedToday(): int
    {
        return Post::where('status', 'published')
            ->whereBetween('published_at', self::todayRange())
            ->count();
    }

    /** The current New York editorial day as a UTC [start, end] range. */
    public static function todayRange(): array
    {
        $tz = config('app.display_timezone', 'America/New_York');

        return [now($tz)->startOfDay()->setTimezone('UTC'), now($tz)->endOfDay()->setTimezone('UTC')];
    }

    /**
     * Slots left today (PHP_INT_MAX when the cap is 0 = disabled). The last
     * breaking_reserve_slots (default 2) of the cap are kept for BREAKING news,
     * so a major story late in the day is never blocked by regular posts. The
     * reserve shrinks as breaking stories use it; the total never exceeds the cap.
     */
    public static function remaining(bool $breaking = false): int
    {
        $cap = self::cap();
        if ($cap <= 0) {
            return PHP_INT_MAX;
        }
        $left = max(0, $cap - self::publishedToday());
        if ($breaking) {
            return $left;
        }
        $breakingToday = Post::where('status', 'published')->where('is_breaking', true)
            ->whereBetween('published_at', self::todayRange())->count();

        return max(0, $left - max(0, self::reserve() - $breakingToday));
    }

    public static function reached(bool $breaking = false): bool
    {
        return self::remaining($breaking) <= 0;
    }

    /**
     * Daily slots reserved per section (category slug => slots), so the day's
     * posts aren't all politics: default 1 World, 1 Story of Hope, 1 Opinion.
     * Setting `category_reserves` (JSON) overrides it.
     *
     * @return array<string,int>
     */
    public static function categoryReserves(): array
    {
        $raw = Setting::get('category_reserves');
        $map = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);

        return is_array($map) ? array_map('intval', $map) : ['world' => 1, 'story-of-hope' => 1, 'opinion' => 1];
    }

    /**
     * Reserved section slots not yet used today (slug => slots). Unused reserves
     * are released at `category_reserve_release` (default 20:00 New York) so a
     * quiet section doesn't waste the day's slots.
     *
     * @return array<string,int>
     */
    public static function unfilledReserves(): array
    {
        $tz = config('app.display_timezone', 'America/New_York');
        if (now($tz)->greaterThanOrEqualTo(self::timeToday((string) Setting::get('category_reserve_release', '20:00'), $tz, 20))) {
            return [];
        }

        $out = [];
        foreach (self::categoryReserves() as $slug => $n) {
            $catId = $n > 0 ? \App\Models\Category::where('slug', $slug)->value('id') : null;
            if (! $catId) {
                continue;
            }
            $used = Post::where('status', 'published')->where('category_id', $catId)
                ->whereBetween('published_at', self::todayRange())->count();
            if ($used < $n) {
                $out[$slug] = $n - $used;
            }
        }

        return $out;
    }

    /**
     * Slots left today for a post in this section: the regular remainder minus
     * the still-unfilled reserves of OTHER sections. Breaking news may use any
     * slot left (it never waits for a section reserve).
     */
    public static function remainingFor(?string $categorySlug, bool $breaking = false): int
    {
        $left = self::remaining($breaking);
        if ($breaking || $left === PHP_INT_MAX) {
            return $left;
        }
        $held = array_sum(array_diff_key(self::unfilledReserves(), [(string) $categorySlug => 0]));

        return max(0, $left - $held);
    }

    /** Slots of the daily cap held back for breaking news. */
    public static function reserve(): int
    {
        return min(max(0, (int) Setting::get('breaking_reserve_slots', 2)), max(0, self::cap() - 1));
    }

    // ── Pacing ───────────────────────────────────────────────────────────────
    // The cap limits HOW MANY posts go out per day; pacing controls WHEN, so the
    // day's posts are spread across a New York publishing window instead of
    // filling the cap in a morning burst (promotion runs every 30 min).
    //   publish_window_start   (default 07:00) — first regular slot, NY time
    //   publish_window_end     (default 22:00) — last regular slot, NY time
    //   min_publish_gap_minutes (default 0 = auto: window ÷ (regular slots − 1))

    /** Today's publishing window as [start, end] in New York time. */
    public static function window(): array
    {
        $tz = config('app.display_timezone', 'America/New_York');
        $start = self::timeToday((string) Setting::get('publish_window_start', '07:00'), $tz, 7);
        $end = self::timeToday((string) Setting::get('publish_window_end', '22:00'), $tz, 22);

        return [$start, $end->greaterThan($start) ? $end : $start->copy()->endOfDay()];
    }

    /** Minimum minutes between regular posts (0 = no gap, cap disabled). */
    public static function gapMinutes(): int
    {
        $override = (int) Setting::get('min_publish_gap_minutes', 0);
        if ($override > 0) {
            return $override;
        }
        $cap = self::cap();
        if ($cap <= 0) {
            return 0;
        }
        [$start, $end] = self::window();
        $regularSlots = max(1, $cap - self::reserve());   // breaking slots don't need spacing

        return max(15, (int) floor($start->diffInMinutes($end) / max(1, $regularSlots - 1)));
    }

    /** When the most recent post (any category or source) went live. */
    public static function lastPublishedAt(): ?\Illuminate\Support\Carbon
    {
        $at = Post::where('status', 'published')->where('published_at', '<=', now())->max('published_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /**
     * May a regular (non-breaking) post go out right now? Only inside the
     * publishing window and once the minimum gap since the last post has passed.
     * Breaking news bypasses pacing — but never the daily cap.
     */
    public static function paceAllows(bool $breaking = false): bool
    {
        if ($breaking) {
            return true;
        }
        [$start, $end] = self::window();
        $now = now($start->getTimezone());
        if ($now->lessThan($start) || $now->greaterThan($end)) {
            return false;
        }
        $last = self::lastPublishedAt();
        $gap = self::gapMinutes();

        return ! $last || $gap <= 0 || $last->diffInMinutes(now()) >= $gap;
    }

    /** Human label for the next regular slot, e.g. "3:40 PM ET". */
    public static function nextSlotLabel(): string
    {
        [$start, $end] = self::window();
        $last = self::lastPublishedAt();
        $next = $last ? $last->copy()->setTimezone($start->getTimezone())->addMinutes(self::gapMinutes()) : $start->copy();
        if ($next->lessThan($start)) {
            $next = $start->copy();
        }
        if ($next->greaterThan($end) || self::reached()) {
            $next = self::window()[0]->copy()->addDay();   // tomorrow's window opens
        }

        return $next->format('D g:i A') . ' ET';
    }

    private static function timeToday(string $hhmm, string $tz, int $fallbackHour): \Illuminate\Support\Carbon
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return now($tz)->startOfDay()->setTime($fallbackHour, 0);
        }

        return now($tz)->startOfDay()->setTime((int) $m[1], (int) $m[2]);
    }
}
