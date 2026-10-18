<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * The single answer to "is there a valid quiz today?" for the homepage, the quiz page, the
 * promotional sections and the generator. A stored quiz counts only when it is from today,
 * was built from recorded published stories, and every one of those stories is still published.
 * Quizzes without that source metadata (including everything created before version 2) are invalid.
 */
class QuizAvailability
{
    public const VERSION = 2;

    /** Fewest eligible recent stories a quiz may be built from. */
    public const MIN_STORIES = 5;

    /** How many stories are shown to the generator at most. */
    public const MAX_STORIES = 14;

    /** Only stories this recent can support "this week's news" questions. */
    public const WINDOW_DAYS = 7;

    public static function today(): string
    {
        return now()->toDateString();
    }

    /** Recent published stories in the quiz categories, newest first. */
    public static function eligiblePosts(): Collection
    {
        $ids = Category::whereIn('slug', ['politics', 'us-news', 'world', 'story-of-hope'])->pluck('id');

        return Post::published()->whereIn('category_id', $ids)
            ->where('published_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->latest('published_at')->take(self::MAX_STORIES)
            ->get(['id', 'title', 'excerpt']);
    }

    public static function enoughStories(): bool
    {
        return self::eligiblePosts()->count() >= self::MIN_STORIES;
    }

    /** The valid quiz for today, or null. */
    public static function current(): ?array
    {
        $quiz = json_decode((string) Setting::get('daily_quiz'), true);
        if (! is_array($quiz)
            || ($quiz['version'] ?? null) !== self::VERSION
            || ($quiz['date'] ?? null) !== self::today()
            || ! is_array($quiz['questions'] ?? null) || count($quiz['questions']) < 3) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($quiz['post_ids'] ?? [])))));
        if (count($ids) < self::MIN_STORIES) {
            return null;
        }
        // Deleted or unpublished source stories invalidate the quiz.
        if (Post::published()->whereIn('id', $ids)->count() !== count($ids)) {
            return null;
        }

        return $quiz;
    }

    public static function available(): bool
    {
        return self::current() !== null;
    }

    /** Bumped by quiz:reset; browsers discard quiz progress saved under an older epoch. */
    public static function epoch(): int
    {
        return max(1, (int) Setting::get('quiz_epoch', 1));
    }
}
