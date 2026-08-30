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
        $minWords = (int) Setting::get('min_publish_words', 500);    // never promote a thin one
        $expireHours = (float) Setting::get('queue_expire_hours', 4);   // news goes stale fast — drop it quickly
        $decay = (float) Setting::get('promote_freshness_decay', 12);   // score points a queued story loses per hour

        // ── 1. Expire stale holds ──────────────────────────────────────────────
        // A queued story that never won a slot within the window is no longer fresh
        // news. Drop it from the queue (clear the marker) so it ages out via the
        // normal stale-draft cleanup instead of being published late.
        $expired = 0;
        if ($expireHours > 0) {
            $stale = Post::where('status', 'draft')->whereNotNull('queued_at')
                ->where('queued_at', '<', now()->subHours($expireHours))->get();
            $expired = $stale->count();
            if (! $dry) {
                foreach ($stale as $p) {
                    $p->forceFill(['queued_at' => null])->saveQuietly();
                }
            }
        }

        // ── 2. How many slots are left today? ──────────────────────────────────
        $publishedToday = Post::where('status', 'published')->whereNotNull('source_name')
            ->whereDate('published_at', today())->count();
        $remaining = $cap <= 0 ? PHP_INT_MAX : max(0, $cap - $publishedToday);

        if ($remaining <= 0) {
            $this->info("Daily cap reached ({$publishedToday}/{$cap}). Expired {$expired} stale. Nothing promoted.");
            return self::SUCCESS;
        }

        // ── 3. Promote the best FRESH queued stories ───────────────────────────
        // News ages fast, so we rank by a FRESHNESS-WEIGHTED score, not raw score:
        // effective = editorial_score − (hours queued × decay). A fresh, strong story
        // beats an older one even if the older scored a little higher — so new content
        // isn't blocked by stale content sitting in the queue. Drop any (still) thin.
        $take = min($remaining, $perRun);
        $now = now();
        $pool = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->where('editorial_score', '>=', $floor)          // below the bar → keep waiting
            ->limit(80)
            ->get()
            ->map(function ($p) use ($decay, $now) {
                $hrs = $p->queued_at ? $p->queued_at->diffInMinutes($now) / 60 : 0;
                $p->setAttribute('effective_score', (float) $p->editorial_score - $hrs * $decay);
                return $p;
            })
            ->sortByDesc('effective_score')
            ->values();

        $promoted = 0;
        foreach ($pool as $post) {
            if ($promoted >= $take) {
                break;
            }
            if (str_word_count(strip_tags((string) $post->body)) < $minWords) {
                continue; // safety: never promote a thin story
            }

            if ($dry) {
                $this->line("  would promote #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
                $promoted++;
                continue;
            }

            // Generate the featured image NOW (deferred from ingest so we only pay
            // for images on stories that actually go live). AI-first, matching the
            // sources' ai_image preference; a blip just leaves the category icon.
            $imageId = $post->featured_image;
            if (blank($imageId)) {
                $prompt = 'Editorial news illustration for a ' . ($post->category?->name ?? 'news')
                    . " story titled: {$post->title}. Photorealistic, tasteful, no text, no logos, no watermarks.";
                $imageId = $images->generate($prompt) ?: null;
            }

            // Publish it. A normal save fires PostObserver (social fan-out); web push
            // is handled separately by the throttled push:notify job.
            $post->forceFill([
                'featured_image' => $imageId,
                'status' => 'published',
                'published_at' => now(),
                'queued_at' => null,
            ])->save();

            // Guarantee search-readiness now that it's live.
            try {
                if ((int) ($post->seo_score ?? 0) < 80) {
                    $booster->boost($post);
                }
            } catch (\Throwable) {
                // non-fatal — the post is still published
            }

            $this->info("  promoted #{$post->id} [score {$post->editorial_score}]  " . Str::limit($post->title, 55));
            $promoted++;
        }

        $waiting = Post::where('status', 'draft')->whereNotNull('queued_at')->count();
        $this->info(($dry ? '[DRY] ' : '') . "Promoted {$promoted} (cap {$publishedToday}→" . ($publishedToday + $promoted) . "/{$cap}) · expired {$expired} stale · {$waiting} still queued.");

        return self::SUCCESS;
    }
}
