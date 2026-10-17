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

    public function enabled(): bool
    {
        return $this->researcher->enabled();
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
