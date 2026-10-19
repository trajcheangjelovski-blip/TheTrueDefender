<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\StorySynthesizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * RESEARCH-FIRST SYNTHESIS for queued drafts — the new content model's writer.
 *
 * For each queued draft: search the open web (Brave) for OTHER outlets covering
 * the same story, then write the whole article ONCE under the editorial policy and
 * run the independent fact-check (EditorialPipeline). Only drafts the fact-check
 * marks "ready" can clear PublishGate and be promoted by posts:promote-queued.
 *
 * Cost-safe: it only spends an AI synthesis call when enough extra sources were
 * actually found to clear the multi-source bar — a story with no other coverage
 * is left in the queue (it will expire) rather than written up single-source.
 *
 * No-op unless a web-search key (Brave) is configured.
 *
 *   php artisan posts:synthesize            # process queued drafts
 *   php artisan posts:synthesize --dry      # show what would happen, change nothing
 */
class PostsSynthesize extends Command
{
    protected $signature = 'posts:synthesize
        {--limit=5 : Max drafts to synthesize this run}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Newsroom pipeline for queued drafts: research other outlets, write under the editorial policy, independent fact-check (+ fix passes). Only "ready" drafts can be promoted. No-op without a web-search key.';

    /**
     * Cost control: GPT-5.4 research is spent only on stories that can realistically
     * win a slot. Per section: its open slots today + 1 spare, minus stories already
     * researched and ready there, taken best-ranked first (StoryRank). Breaking news
     * always qualifies. Outside the publishing window (except its last 30 minutes
     * before opening) only breaking news is researched. research_top_candidates_only=0
     * researches every queued story.
     */
    private function slotCandidates(\Illuminate\Support\Collection $posts): \Illuminate\Support\Collection
    {
        if (! (bool) (int) Setting::get('research_top_candidates_only', '1')) {
            return $posts;
        }
        [$start, $end] = \App\Support\DailyCap::window();
        $now = now($start->getTimezone());
        $inWindow = $now->gte($start->copy()->subMinutes(30)) && $now->lte($end);
        $spare = max(0, (int) Setting::get('research_candidates_spare', 1));
        $slugs = \App\Models\Category::pluck('slug', 'id');
        $open = \App\Support\DailyCap::unfilledSlots();
        $released = \App\Support\DailyCap::released();
        $readyBySection = Post::where('status', 'draft')->whereNotNull('queued_at')->where('review_status', 'ready')
            ->get(['id', 'category_id'])->countBy(fn ($p) => $slugs[$p->category_id] ?? '?');
        $readyTotal = $readyBySection->sum();

        $taken = [];
        $out = collect();
        foreach ($posts->sortByDesc(fn ($p) => \App\Support\StoryRank::rank($p, $slugs[$p->category_id] ?? null)) as $p) {
            $slug = $slugs[$p->category_id] ?? '?';
            if ($p->is_breaking) {
                $out->push($p);   // the fast lane always researches breaking news

                continue;
            }
            if (! $inWindow) {
                continue;
            }
            $room = $released
                ? max(0, \App\Support\DailyCap::remaining() + $spare - $readyTotal - array_sum($taken))
                : max(0, ($open[$slug] ?? 0) + (($open[$slug] ?? 0) > 0 ? $spare : 0) - ($readyBySection[$slug] ?? 0) - ($taken[$slug] ?? 0));
            if ($room > 0) {
                $taken[$slug] = ($taken[$slug] ?? 0) + 1;
                $out->push($p);
            } else {
                $this->line("  · #{$p->id} not a slot candidate right now — not researched (saves research cost)  " . Str::limit($p->title, 40));
            }
        }

        return $out;
    }

    public function handle(StorySynthesizer $synth): int
    {
        if (! $synth->enabled()) {
            $this->info('Web research (Brave) is disabled — no web-search key set. Nothing to do.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry');
        $minSources = (int) Setting::get('min_sources_to_publish', 3);

        // Not yet researched — or sent back by the fact-check as "needs research"
        // 30+ minutes ago, so other outlets may now have it (max 3 retries).
        $posts = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->where(fn ($q) => $q->whereNull('web_researched_at')->orWhere(fn ($q) => $q
                ->where('review_status', 'needs_research')->where('web_researched_at', '<=', now()->subMinutes(30))))
            ->orderByDesc('editorial_score')
            ->orderByDesc('queued_at')
            ->limit(200)
            ->get()
            ->filter(fn ($p) => blank($p->web_researched_at) || \App\Services\StorySynthesizer::retryDue($p, 30));
        $posts = $this->slotCandidates($posts)->take($limit)->values();

        if ($posts->isEmpty()) {
            $this->info('No queued drafts awaiting synthesis.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY] ' : '') . "Synthesizing up to {$posts->count()} draft(s)…");
        $done = 0;

        foreach ($posts as $post) {
            $found = [];
            if (! $dry && filled($post->web_researched_at)) {
                \App\Services\StorySynthesizer::noteRetry($post);
            }
            $why = $synth->synthesize($post, $minSources, $dry, $found);

            if ($why !== null) {
                $this->line("  · #{$post->id} {$why} — left queued  " . Str::limit($post->title, 40));
                continue;
            }
            if ($dry) {
                $this->line("  ~ #{$post->id} would synthesize with: " . collect($found)->pluck('name')->implode(', ') . '  — ' . Str::limit($post->title, 40));
                continue;
            }

            $post->refresh();
            $this->info("  ✓ #{$post->id} written from " . count((array) $post->sources) . " sources · fact-check: {$post->review_status} ("
                . (int) ($post->fact_check['critical'] ?? 0) . ' critical)  — ' . Str::limit($post->title, 40));
            $done++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Done — synthesized {$done} draft(s). promote-queued publishes only drafts the fact-check marked \"ready\".");

        return self::SUCCESS;
    }
}
