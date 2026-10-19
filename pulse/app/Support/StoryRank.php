<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Setting;

/**
 * How a queued story is ranked for its section's next slot:
 *
 *   rank = final score            (0-100, the written article judged in its own section)
 *        + coverage boost         (+5 per other outlet running the story, max +15)
 *        + verified bonus         (+5 when the independent fact-check said "ready")
 *        − age penalty            (hours since the outlet published × the section's rate:
 *                                  12/h for news, 4/h for Story of Hope — section_age_decay)
 *
 * The section itself is chosen first (the one with the largest share of its
 * daily slots still open — see posts:promote-queued); this ranks within it.
 */
class StoryRank
{
    public const COVERAGE_PER_OUTLET = 5;

    public const COVERAGE_MAX = 15;

    public const VERIFIED_BONUS = 5;

    /** Points lost per hour of age, per section (others: promote_freshness_decay, default 12). */
    public const DEFAULT_DECAY = ['story-of-hope' => 4];

    public static function coverageBoost(Post $post): int
    {
        $outlets = count(array_unique(array_filter((array) $post->coverage_outlets)));

        return min(self::COVERAGE_MAX, max(0, $outlets - 1) * self::COVERAGE_PER_OUTLET);
    }

    public static function verifiedBonus(Post $post): int
    {
        return $post->review_status === 'ready' ? self::VERIFIED_BONUS : 0;
    }

    public static function decay(?string $section): float
    {
        $raw = Setting::get('section_age_decay');
        $map = (is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null)) ?: self::DEFAULT_DECAY;

        return (float) ($map[(string) $section] ?? Setting::get('promote_freshness_decay', 12));
    }

    public static function agePenalty(Post $post, ?string $section): float
    {
        $since = $post->source_published_at ?? $post->queued_at;

        return $since ? round($since->diffInMinutes(now()) / 60 * self::decay($section), 1) : 0.0;
    }

    public static function rank(Post $post, ?string $section): float
    {
        return (int) $post->editorial_score + self::coverageBoost($post) + self::verifiedBonus($post) - self::agePenalty($post, $section);
    }

    /** e.g. "score 78 + coverage 10 + verified 5 − age 6.0 = 87.0" */
    public static function explain(Post $post, ?string $section): string
    {
        return sprintf('score %d + coverage %d + verified %d − age %.1f = %.1f',
            (int) $post->editorial_score, self::coverageBoost($post), self::verifiedBonus($post),
            self::agePenalty($post, $section), self::rank($post, $section));
    }

    // (The single-source fallback was removed 2026-10-19: every news story is now
    // researched and independently fact-checked before it can publish.)
}
