<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\ImageService;
use App\Services\SeoBooster;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PostsPromoteQueued extends Command
{
    protected $signature = 'posts:promote-queued
        {--per-run=1 : Max stories to promote on this run (pacing)}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Publish the highest-scoring QUEUED stories into the day\'s remaining slots (topic-priority, best-first), waiting when nothing clears the bar. Breaking news is published immediately at ingest and is not handled here.';

    public function handle(ImageService $images, SeoBooster $booster): int
    {
        $dry = (bool) $this->option('dry');
        $perRun = max(1, (int) $this->option('per-run'));

        $cap = (int) Setting::get('daily_publish_cap', 0);
        $floor = (int) Setting::get('promote_score_floor', 60);      // quality bar to auto-promote
        // Word/source/quality floors are enforced centrally by PublishGate.
        $expireHours = (float) Setting::get('queue_expire_hours', 4);   // stop promoting a story older than this
        $deleteHours = (float) Setting::get('draft_expire_hours', 6);   // then DELETE the unpublished draft entirely
        $decay = (float) Setting::get('promote_freshness_decay', 12);   // score points a queued story loses per hour

        // ── 1. Expire stale holds ──────────────────────────────────────────────
        // Two stages, so unpublished news can't pile up as drafts:
        //  (a) past queue_expire_hours (4h): stop competing for a slot (dequeue).
        //  (b) past draft_expire_hours (6h): DELETE the draft — stale news we were
        //      never going to publish. Only AUTO-ingested drafts (have a source_url)
        //      are deleted; a human's manual draft is never touched. Runs every
        //      promote cycle (~30 min), so drafts vanish within hours, not a day.
        $expired = $dropped = 0;
        if (! $dry) {
            if ($deleteHours > 0) {
                // Hard age limit from when the story was ingested, so a draft is
                // always gone ~draft_expire_hours after creation (not extended by
                // re-queue). News older than this is stale and not worth holding.
                $dropped = Post::where('status', 'draft')
                    ->whereNotNull('source_url')
                    ->where('created_at', '<', now()->subHours($deleteHours))
                    ->delete();
            }
            if ($expireHours > 0) {
                $expired = Post::where('status', 'draft')->whereNotNull('queued_at')
                    ->where('queued_at', '<', now()->subHours($expireHours))
                    ->update(['queued_at' => null]);
            }
        }

        // ── 2. How many slots are left today? ──────────────────────────────────
        // Counts ALL posts published today (news, opinion, manual) so the ceiling
        // is a true total shared with every other publish path.
        $publishedToday = \App\Support\DailyCap::publishedToday();
        $remaining = \App\Support\DailyCap::remaining();                     // regular slots
        $remainingBreaking = \App\Support\DailyCap::remaining(breaking: true); // incl. reserve

        if ($remainingBreaking <= 0) {
            $this->info("Daily cap reached ({$publishedToday}/{$cap}). Deleted {$dropped} stale draft(s), dequeued {$expired}. Nothing promoted.");
            return self::SUCCESS;
        }

        // ── 2b. Pacing: spread the cap across the day ──────────────────────────
        // Outside the New York publishing window, before the minimum gap since the
        // last post has passed, or once only the reserved breaking slots are left,
        // only BREAKING stories may go out (still capped).
        $breakingOnly = $remaining <= 0 || ! \App\Support\DailyCap::paceAllows();
        if ($breakingOnly) {
            $this->line(($remaining <= 0 ? 'Only reserved breaking slots left' : 'Pacing: next regular slot ' . \App\Support\DailyCap::nextSlotLabel()
                . ' (gap ' . \App\Support\DailyCap::gapMinutes() . ' min)') . ' — only breaking news may publish now.');
        }

        // ── 3. Promote the best FRESH queued stories ───────────────────────────
        // News ages fast, so we rank by a FRESHNESS-WEIGHTED score, not raw score:
        // effective = editorial_score − (hours queued × decay). A fresh, strong story
        // beats an older one even if the older scored a little higher — so new content
        // isn't blocked by stale content sitting in the queue. Drop any (still) thin.
        $take = min($breakingOnly ? $remainingBreaking : $remaining, $perRun);
        $now = now();
        // Freshness-weighted score is computed ONLY for sorting — never stored on
        // the model, or Eloquent would try to persist it as a column on save().
        $effective = fn ($p) => (float) $p->editorial_score
            - ($p->queued_at ? $p->queued_at->diffInMinutes($now) / 60 : 0) * $decay;

        // Reserved section slots (World, Story of Hope — see DailyCap::categoryReserves):
        // until a section's slot is used today, its best queued candidate jumps the
        // line (still gated, paced and capped). Otherwise those sections would always
        // lose to politics on raw score and stay empty.
        $catSlugs = \App\Models\Category::pluck('slug', 'id');
        $needSlugs = array_keys(\App\Support\DailyCap::unfilledReserves());
        $rank = fn ($p) => (in_array($catSlugs[$p->category_id] ?? null, $needSlugs, true) ? 10000 : 0) + $effective($p);

        $pool = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->where('editorial_score', '>=', $floor)          // below the bar → keep waiting
            ->when($breakingOnly, fn ($q) => $q->where('is_breaking', true))
            ->limit(80)
            ->get()
            ->sortByDesc($rank)
            ->values();

        $promoted = 0;
        $blocked = 0;
        foreach ($pool as $post) {
            if ($promoted >= $take) {
                break;
            }

            // Section reserves: a politics story may not take the slot held for
            // World / Story of Hope / Opinion (breaking news may use any slot).
            if (\App\Support\DailyCap::remainingFor($catSlugs[$post->category_id] ?? null, (bool) $post->is_breaking) <= 0) {
                $this->line("  wait #{$post->id} — remaining slots are reserved for other sections  " . Str::limit($post->title, 40));
                continue;
            }

            // New-model publish bar: multi-source, substantial, no single-source
            // tells / outreach / placeholders. A story that fails waits (it can be
            // enriched by posts:research) instead of publishing as low-value content.
            if ($fails = \App\Support\PublishGate::failures($post, $post->is_breaking ? \App\Services\BreakingPublisher::minSources() : null)) {
                $blocked++;
                $this->line("  skip #{$post->id} — " . implode('; ', array_values($fails)) . '  ' . Str::limit($post->title, 40));
                continue;
            }

            if ($dry) {
                $this->line("  would promote #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
                $promoted++;
                continue;
            }

            // Image, status, search metadata — shared with the breaking fast lane.
            \App\Support\Publisher::publish($post, $images, $booster);

            $this->info("  promoted #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
            $promoted++;
        }

        $waiting = Post::where('status', 'draft')->whereNotNull('queued_at')->count();
        $this->info(($dry ? '[DRY] ' : '') . "Promoted {$promoted} · blocked {$blocked} (below bar) · deleted {$dropped} stale draft(s) · dequeued {$expired} · cap {$publishedToday}→" . ($publishedToday + $promoted) . "/{$cap} · {$waiting} still queued.");

        return self::SUCCESS;
    }
}
