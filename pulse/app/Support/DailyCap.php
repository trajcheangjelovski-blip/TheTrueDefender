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

    /** How many posts have gone live today (every category and source). */
    public static function publishedToday(): int
    {
        return Post::where('status', 'published')
            ->whereDate('published_at', today())
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
