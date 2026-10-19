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
        // Quality bar: each section's own score bar (DailyCap::scoreBar) — stories
        // are scored within their section. Word/source/quality floors: PublishGate.
        $expireHours = (float) Setting::get('queue_expire_hours', 4);   // stop promoting a story older than this
        $deleteHours = (float) Setting::get('draft_expire_hours', 6);   // then DELETE the unpublished draft entirely

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

        // ── 3. Give each open slot to the BEST story, not the first ─────────────
        // Section first: the one with the largest share of its daily slots still
        // open (DailyCap::sectionSlots), so the day rotates Politics / US News /
        // World / Story of Hope. Within it, App\Support\StoryRank:
        //   final score + coverage boost + verified bonus − age penalty
        // (age measured from when the ORIGINAL outlet published). Each story must
        // also clear its own section's score bar. Ranks are computed only for
        // sorting — never stored on the model.
        $take = min($breakingOnly ? $remainingBreaking : $remaining, $perRun);
        $catSlugs = \App\Models\Category::pluck('slug', 'id');
        $slots = \App\Support\DailyCap::sectionSlots();
        $open = \App\Support\DailyCap::unfilledSlots();
        $bar = fn ($p) => \App\Support\DailyCap::scoreBar($catSlugs[$p->category_id] ?? null);
        $rank = function ($p) use ($catSlugs, $slots, $open) {
            $slug = $catSlugs[$p->category_id] ?? null;
            $share = ($slots[$slug] ?? 0) > 0 ? ($open[$slug] ?? 0) / $slots[$slug] : 0;

            return $share * 10000 + \App\Support\StoryRank::rank($p, $slug);
        };

        $pool = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->when($breakingOnly, fn ($q) => $q->where('is_breaking', true))
            ->orderByDesc('editorial_score')
            ->limit(120)
            ->get()
            ->filter(fn ($p) => (int) $p->editorial_score >= $bar($p))   // below its section's bar → keep waiting
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
                $this->line("  wait #{$post->id} — its section's slots are used for today (others still open until the evening release)  " . Str::limit($post->title, 40));
                continue;
            }

            // Publish bar: researched (gpt-5.4), written from the research, independently
            // fact-checked (gpt-5.4) "ready", multi-source, substantial, no tells or
            // placeholders — then the FINAL check (event identity, freshness, breaking
            // label, live re-check of developing stories). A story that fails waits.
            $fails = \App\Support\PublishGate::failures($post, $post->is_breaking ? \App\Services\BreakingPublisher::minSources() : null);
            if (! $fails && ! $dry) {
                $fails = \App\Support\FinalCheck::failures($post);
            }
            $via = 'researched + fact-checked';
            if ($fails) {
                $blocked++;
                $this->line("  skip #{$post->id} — " . implode('; ', array_values($fails)) . '  ' . Str::limit($post->title, 40));
                continue;
            }

            $slug = $catSlugs[$post->category_id] ?? '?';
            $why = "[{$slug}] " . \App\Support\StoryRank::explain($post, $slug) . " ({$via})";
            if ($dry) {
                $this->line("  would promote #{$post->id} {$why}  " . Str::limit($post->title, 50));
                $promoted++;
                continue;
            }

            // Image, status, search metadata — shared with the breaking fast lane.
            \App\Support\Publisher::publish($post, $images, $booster);

            $this->info("  promoted #{$post->id} {$why}  " . Str::limit($post->title, 50));
            \Illuminate\Support\Facades\Log::info("Promoted #{$post->id} {$why}: {$post->title}");
            $promoted++;
        }

        $waiting = Post::where('status', 'draft')->whereNotNull('queued_at')->count();
        $this->info(($dry ? '[DRY] ' : '') . "Promoted {$promoted} · blocked {$blocked} (below bar) · deleted {$dropped} stale draft(s) · dequeued {$expired} · cap {$publishedToday}→" . ($publishedToday + $promoted) . "/{$cap} · {$waiting} still queued.");

        return self::SUCCESS;
    }
}
