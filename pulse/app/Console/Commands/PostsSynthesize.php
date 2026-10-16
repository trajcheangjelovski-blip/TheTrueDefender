<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\ArticleFetcher;
use App\Services\Rewriter;
use App\Services\SeoOptimizer;
use App\Services\WebResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * RESEARCH-FIRST SYNTHESIS for queued drafts — the new content model's writer.
 *
 * For each queued draft: search the open web (Brave) for OTHER outlets covering
 * the same story, then write the whole article ONCE from the primary report plus
 * those extra sources (multi-source, no outlet names in the body), ending with a
 * labeled "Our Take" opinion section. The post is then ready to clear PublishGate
 * and be promoted by posts:promote-queued.
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

    protected $description = 'Research-first synthesis: Brave-search other outlets, write a multi-source article + "Our Take", ready for promotion. No-op without a web-search key.';

    public function handle(WebResearcher $researcher, Rewriter $rewriter, ArticleFetcher $fetcher, SeoOptimizer $seo): int
    {
        if (! $researcher->enabled()) {
            $this->info('Web research (Brave) is disabled — no web-search key set. Nothing to do.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry');
        $cap = (int) Setting::get('max_sources_per_post', 4);
        $minSources = (int) Setting::get('min_sources_to_publish', 3);
        $neededExtra = max(1, $minSources - 1); // primary counts as one

        $posts = Post::where('status', 'draft')
            ->whereNotNull('queued_at')
            ->whereNotNull('source_url')
            ->whereNull('web_researched_at')
            ->orderByDesc('editorial_score')
            ->orderByDesc('queued_at')
            ->limit($limit)
            ->get();

        if ($posts->isEmpty()) {
            $this->info('No queued drafts awaiting synthesis.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY] ' : '') . "Synthesizing up to {$posts->count()} draft(s)…");
        $done = 0;

        foreach ($posts as $post) {
            // Primary source text: re-fetch the original article; fall back to the
            // existing draft body if the fetch fails.
            $primary = '';
            try {
                $primary = (string) ($fetcher->extract($post->source_url)['text'] ?? '');
            } catch (\Throwable) {
            }
            if (blank($primary)) {
                $primary = strip_tags((string) $post->body);
            }

            // Outlets already attached (so we don't fetch the same one twice).
            $sources = is_array($post->sources) ? $post->sources : [];
            if (empty($sources) && filled($post->source_url)) {
                $sources = [['name' => $post->source_name, 'url' => $post->source_url]];
            }
            $hosts = collect($sources)
                ->map(fn ($s) => Str::lower((string) parse_url($s['url'] ?? '', PHP_URL_HOST)))
                ->map(fn ($h) => Str::startsWith($h, 'www.') ? substr($h, 4) : $h)
                ->filter()->all();

            $extra = $researcher->research($post->title, $hosts, max(0, $cap - count($sources)));

            if (count($extra) < $neededExtra) {
                $this->line("  · #{$post->id} only " . count($extra) . " extra source(s) found (need {$neededExtra}) — left queued  " . Str::limit($post->title, 40));
                continue;
            }

            if ($dry) {
                $names = collect($extra)->pluck('name')->implode(', ');
                $this->line("  ~ #{$post->id} would synthesize with: {$names}  — " . Str::limit($post->title, 40));
                continue;
            }

            $result = $rewriter->synthesize($post->title, $primary, $extra);
            if (! $result || blank($result['body'] ?? null)) {
                $this->line("  · #{$post->id} synthesis produced nothing — left queued");
                continue;
            }

            // Record every outlet used (for the Sources list), capped.
            foreach ($extra as $src) {
                $sources[] = ['name' => $src['name'], 'url' => $src['url']];
            }
            $sources = array_slice($sources, 0, $cap);

            $post->forceFill([
                'body' => $result['body'],
                'sources' => array_values($sources),
                'web_researched_at' => now(),
            ])->save();

            try {
                $seo->optimizePost($post);
            } catch (\Throwable) {
            }

            $this->info("  ✓ #{$post->id} synthesized from " . count($sources) . " sources + Our Take  — " . Str::limit($post->title, 40));
            $done++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Done — synthesized {$done} draft(s). promote-queued will publish those that clear the bar.");

        return self::SUCCESS;
    }
}
