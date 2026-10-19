<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;

/**
 * Writes ONE queued draft through the newsroom pipeline (research → write →
 * independent fact-check → one fix pass → records). Shared by posts:synthesize
 * (regular queue) and BreakingPublisher (fast lane), so both follow the same
 * editorial policy. The draft's review_status records the fact-check verdict;
 * PublishGate only lets "ready" drafts go live.
 */
class StorySynthesizer
{
    public function __construct(
        private WebResearcher $researcher,
        private EditorialPipeline $pipeline,
    ) {}

    /** How many times a "needs research" draft may be researched and rewritten again. */
    public const MAX_RETRIES = 3;

    public function enabled(): bool
    {
        return $this->researcher->enabled();
    }

    /**
     * A draft the fact-check sent back as "needs research" (e.g. a story only one
     * outlet had at first) may be researched and written again once other outlets
     * have had time to report it — every $afterMinutes, at most MAX_RETRIES times
     * (each retry is a paid research + write + fact-check run).
     */
    public static function retryDue(Post $post, int $afterMinutes): bool
    {
        return $post->review_status === 'needs_research'
            && $post->web_researched_at && $post->web_researched_at->lte(now()->subMinutes($afterMinutes))
            && (int) Cache::get("re-research:{$post->id}", 0) < self::MAX_RETRIES;
    }

    public static function noteRetry(Post $post): void
    {
        Cache::put("re-research:{$post->id}", (int) Cache::get("re-research:{$post->id}", 0) + 1, now()->addDay());
    }

    /**
     * Returns null when the draft was written and fact-checked, or a short reason
     * it was left as is (not enough sources yet, busy, writer failed, …).
     *
     * @param  array<int,array>|null  $foundOut  sources found (for --dry)
     */
    public function synthesize(Post $post, int $minSources, bool $dry = false, ?array &$foundOut = null): ?string
    {
        if (! $this->enabled()) {
            return 'web research disabled (no web-search key)';
        }
        if ($dry) {
            $foundOut = $this->pipeline->gatherSources($post, 5);

            return count($foundOut) >= $minSources ? null : 'only ' . count($foundOut) . " usable source(s) (need {$minSources})";
        }

        // posts:synthesize and the breaking fast lane can reach the same draft at once.
        $lock = Cache::lock("synthesize-post:{$post->id}", 600);
        if (! $lock->get()) {
            return 'already being synthesized';
        }

        try {
            return $this->pipeline->processDraft($post, $minSources);
        } finally {
            $lock->release();
        }
    }
}
