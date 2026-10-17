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
        $tz = config('app.display_timezone', 'America/New_York');
        $start = now($tz)->startOfDay()->setTimezone('UTC');
        $end = now($tz)->endOfDay()->setTimezone('UTC');

        return Post::where('status', 'published')
            ->whereBetween('published_at', [$start, $end])
            ->count();
    }

    /** Slots left today. PHP_INT_MAX when the cap is 0 (disabled). */
    public static function remaining(): int
    {
        $cap = self::cap();

        return $cap <= 0 ? PHP_INT_MAX : max(0, $cap - self::publishedToday());
    }

    public static function reached(): bool
    {
        return self::remaining() <= 0;
    }
}
