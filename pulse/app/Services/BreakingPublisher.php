<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use App\Support\DailyCap;
use App\Support\PublishGate;
use App\Support\Publisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * BREAKING-NEWS FAST LANE. A breaking story is researched, written, checked and
 * published in one step the moment it is found, instead of waiting for the
 * 10-min synthesis and 30-min promotion runs. Target: live within ~10 minutes of
 * reaching our feeds.
 *
 * Called inline by ingest when a story is flagged breaking, and retried every
 * minute by posts:breaking while other outlets catch up. It uses the daily
 * cap's reserved breaking slots and bypasses pacing — but never the cap itself
 * or the publish bar (only the source minimum is lower, breaking_min_sources,
 * default 2, so a story can go live before a third outlet has it).
 */
class BreakingPublisher
{
    public function __construct(
        private StorySynthesizer $synth,
        private ImageService $images,
        private SeoBooster $booster,
    ) {}

    public static function minSources(): int
    {
        return max(1, (int) Setting::get('breaking_min_sources', 2));
    }

    /** Try to publish a breaking draft now. Returns 'published' or why it is waiting. */
    public function attempt(Post $post): string
    {
        if ($post->status !== 'draft') {
            return 'not a draft';
        }
        if (DailyCap::reached(breaking: true)) {
            return 'daily cap reached';
        }

        if (blank($post->web_researched_at)) {
            if ($why = $this->synth->synthesize($post, self::minSources())) {
                return $why;
            }
            $post->refresh();
        }

        if ($fails = PublishGate::failures($post, self::minSources())) {
            // One re-write if the article only failed an originality check (echoed
            // headline, repeated sentence, stock heading) — a breaking story should
            // not be lost to a wording slip.
            $wording = array_intersect(array_keys($fails), ['headline-echo', 'repeated-sentence', 'reused-sentence', 'generic-heading']);
            if ($wording && count($wording) === count($fails) && Cache::add("breaking-rewrite:{$post->id}", 1, 3600)) {
                $post->forceFill(['web_researched_at' => null])->saveQuietly();

                return $this->attempt($post->refresh());
            }

            return 'held: ' . implode('; ', array_values($fails));
        }

        Publisher::publish($post, $this->images, $this->booster);
        Log::info("Breaking fast lane published #{$post->id}: {$post->title}");

        return 'published';
    }
}
