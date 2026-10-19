<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;

/**
 * The daily publishing plan, in one place. EVERY path that publishes news
 * (queue promotion, immediate ingest, the breaking fast lane) checks it.
 *
 *   daily_publish_cap (15)        — news posts per day, all sections together
 *   breaking_reserve_slots (2)    — of those, held for BREAKING news (any section)
 *   category_quotas               — the rest split into fixed section slots
 *                                   (default Politics 4, US News 4, World 3, Story of Hope 2)
 *   category_reserve_release (20:00 NY) — slots still unused then (section or
 *                                   breaking) open to the best story from any section
 *   section_score_bars            — each section's own AI-score bar (see scoreBar())
 *   opinion_daily_count (1)       — Opinion columns, counted SEPARATELY from news
 *
 * Counts every published non-Opinion post today, including manual ones.
 */
class DailyCap
{
    /** Default section slots (category slug => posts per day). */
    public const DEFAULT_SLOTS = ['politics' => 4, 'us-news' => 4, 'world' => 3, 'story-of-hope' => 2];

    /** Default per-section AI-score bars (each section is scored on its own scale). */
    public const DEFAULT_BARS = ['politics' => 65, 'us-news' => 65, 'world' => 50, 'story-of-hope' => 45];

    public static function cap(): int
    {
        return (int) Setting::get('daily_publish_cap', 0);
    }

    /**
     * How many NEWS posts have gone live today (every section except Opinion,
     * which has its own daily count — see opinionRemaining()).
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
        return self::newsToday()->count();
    }

    /** Published-today query for news (Opinion excluded). */
    private static function newsToday(): \Illuminate\Database\Eloquent\Builder
    {
        $opinionId = self::opinionId();

        return Post::where('status', 'published')
            ->whereBetween('published_at', self::todayRange())
            ->when($opinionId, fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', '!=', $opinionId)));
    }

    private static function opinionId(): int
    {
        return (int) \App\Models\Category::where('slug', 'opinion')->value('id');
    }

    // ── Opinion (separate from the news cap) ─────────────────────────────────

    public static function opinionCap(): int
    {
        return max(0, (int) Setting::get('opinion_daily_count', 1));
    }

    /** Opinion columns that may still be published today. */
    public static function opinionRemaining(): int
    {
        $opinionId = self::opinionId();
        $used = $opinionId ? Post::where('status', 'published')->where('category_id', $opinionId)
            ->whereBetween('published_at', self::todayRange())->count() : 0;

        return max(0, self::opinionCap() - $used);
    }

    /** The current New York editorial day as a UTC [start, end] range. */
    public static function todayRange(): array
    {
        $tz = config('app.display_timezone', 'America/New_York');

        return [now($tz)->startOfDay()->setTimezone('UTC'), now($tz)->endOfDay()->setTimezone('UTC')];
    }

    /**
     * News slots left today (PHP_INT_MAX when the cap is 0 = disabled). Until the
     * release time the last breaking_reserve_slots (default 2) are kept for
     * BREAKING news, so a major story is never blocked by regular posts; the
     * reserve shrinks as breaking stories use it. The total never exceeds the cap.
     */
    public static function remaining(bool $breaking = false): int
    {
        $cap = self::cap();
        if ($cap <= 0) {
            return PHP_INT_MAX;
        }
        $left = max(0, $cap - self::publishedToday());
        if ($breaking || self::released()) {
            return $left;
        }
        $breakingToday = self::newsToday()->where('is_breaking', true)->count();

        return max(0, $left - max(0, self::reserve() - $breakingToday));
    }

    public static function reached(bool $breaking = false): bool
    {
        return self::remaining($breaking) <= 0;
    }

    /**
     * Fixed daily slots per section (category slug => posts), so every section
     * gets new posts every day instead of politics winning every slot on score.
     * Setting `category_quotas` (JSON) overrides DEFAULT_SLOTS.
     *
     * @return array<string,int>
     */
    public static function sectionSlots(): array
    {
        $map = self::jsonSetting('category_quotas');

        return $map ? array_map(fn ($n) => max(0, (int) $n), $map) : self::DEFAULT_SLOTS;
    }

    /**
     * The AI-score bar a story must reach in this section (stories are scored
     * against their OWN section — see Rewriter::score). Setting
     * `section_score_bars` (JSON) overrides DEFAULT_BARS; sections without a bar
     * fall back to publish_score_threshold.
     */
    public static function scoreBar(?string $categorySlug): int
    {
        $bars = self::jsonSetting('section_score_bars') ?: self::DEFAULT_BARS;

        return (int) ($bars[(string) $categorySlug] ?? Setting::get('publish_score_threshold', 0));
    }

    /** After the release time (default 20:00 New York) unused slots open to every section. */
    public static function released(): bool
    {
        $tz = config('app.display_timezone', 'America/New_York');

        return now($tz)->greaterThanOrEqualTo(self::timeToday((string) Setting::get('category_reserve_release', '20:00'), $tz, 20));
    }

    /** Regular (non-breaking) news this section published today. */
    public static function usedToday(string $categorySlug): int
    {
        $catId = \App\Models\Category::where('slug', $categorySlug)->value('id');

        return $catId ? self::newsToday()->where('category_id', $catId)->where('is_breaking', false)->count() : 0;
    }

    /**
     * Section slots not yet used today (slug => slots left). Empty after the
     * release time, when leftovers open to every section.
     *
     * @return array<string,int>
     */
    public static function unfilledSlots(): array
    {
        if (self::released()) {
            return [];
        }
        $out = [];
        foreach (self::sectionSlots() as $slug => $n) {
            if ($n > 0 && ($left = $n - self::usedToday($slug)) > 0) {
                $out[$slug] = $left;
            }
        }

        return $out;
    }

    /**
     * Slots left today for a post in this section. Before the release time a
     * regular story may only use its own section's slots; afterwards any slot
     * left. Breaking news may use any slot left at any time.
     */
    public static function remainingFor(?string $categorySlug, bool $breaking = false): int
    {
        $left = self::remaining($breaking);
        if ($breaking || $left === PHP_INT_MAX || self::released()) {
            return $left;
        }

        return min($left, self::unfilledSlots()[(string) $categorySlug] ?? 0);
    }

    /** Slots of the daily cap held back for breaking news. */
    public static function reserve(): int
    {
        return min(max(0, (int) Setting::get('breaking_reserve_slots', 2)), max(0, self::cap() - 1));
    }

    /** @return array<string,mixed>|null */
    private static function jsonSetting(string $key): ?array
    {
        $raw = Setting::get($key);
        $map = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);

        return is_array($map) && $map !== [] ? $map : null;
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

    /** When the most recent NEWS post went live (Opinion columns don't affect news pacing). */
    public static function lastPublishedAt(): ?\Illuminate\Support\Carbon
    {
        $opinionId = self::opinionId();
        $at = Post::where('status', 'published')->where('published_at', '<=', now())
            ->when($opinionId, fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', '!=', $opinionId)))
            ->max('published_at');

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
