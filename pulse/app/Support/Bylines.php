<?php

namespace App\Support;

use App\Models\User;

/**
 * Maps a content category to the public author who covers that beat, so every
 * new story is bylined to the right named journalist. Falls back to null (the
 * brand byline) for unmapped categories or missing authors.
 */
class Bylines
{
    /** Category slug => author slug (their beat). */
    private const BEATS = [
        'politics' => 'daniel-hayes',       // Politics & National Affairs
        'us-news' => 'daniel-hayes',
        'opinion' => 'daniel-hayes',
        'world' => 'nathan-reed',           // World & Story of Hope
        'story-of-hope' => 'nathan-reed',
    ];

    /** @var array<string,int|null> author slug => user id (per-request cache) */
    private static array $cache = [];

    /** The public author id who covers this category, or null if none/unmapped. */
    public static function forCategorySlug(?string $slug): ?int
    {
        $authorSlug = self::BEATS[$slug] ?? null;
        if (! $authorSlug) {
            return null;
        }

        if (! array_key_exists($authorSlug, self::$cache)) {
            self::$cache[$authorSlug] = User::where('slug', $authorSlug)
                ->where('is_author', true)
                ->value('id');
        }

        return self::$cache[$authorSlug];
    }
}
