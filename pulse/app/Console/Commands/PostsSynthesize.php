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
            ->limit($limit * 3)
            ->get()
            ->filter(fn ($p) => blank($p->web_researched_at) || \App\Services\StorySynthesizer::retryDue($p, 30))
            ->take($limit)
            ->values();

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
