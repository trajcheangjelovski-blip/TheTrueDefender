<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\SeoOptimizer;
use App\Services\Rewriter;
use App\Services\WebResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PostsResearch extends Command
{
    protected $signature = 'posts:research
        {--limit=5 : Max posts to research this run}
        {--window-hours=24 : Only research posts published within this window}
        {--dry : Show what would happen, change nothing}';

    protected $description = 'Web research: for recent published posts, search the open web for other outlets\' coverage of the story and synthesize their new facts in (multi-source, grounded). No-op unless a web-search key is configured.';

    public function handle(WebResearcher $researcher, Rewriter $rewriter, SeoOptimizer $seo): int
    {
        if (! $researcher->enabled()) {
            $this->info('Web research is disabled (no web-search key set). Nothing to do.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $windowH = (float) $this->option('window-hours');
        $dry = (bool) $this->option('dry');
        $cap = (int) Setting::get('max_sources_per_post', 4);

        $posts = Post::where('status', 'published')
            ->whereNotNull('source_url')
            ->whereNull('web_researched_at')
            ->where('published_at', '>=', now()->subHours($windowH))
            ->latest('published_at')
            ->limit($limit)
            ->get();

        if ($posts->isEmpty()) {
            $this->info('No recent un-researched posts. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY] ' : '') . "Researching {$posts->count()} post(s)…");
        $added = 0;

        foreach ($posts as $post) {
            $sources = is_array($post->sources) ? $post->sources : [];
            $hosts = collect($sources)->map(fn ($s) => Str::lower((string) parse_url($s['url'] ?? '', PHP_URL_HOST)))
                ->map(fn ($h) => Str::startsWith($h, 'www.') ? substr($h, 4) : $h)->filter()->all();
            $room = max(0, $cap - count($sources));

            if ($room <= 0) {
                if (! $dry) {
                    $post->forceFill(['web_researched_at' => now()])->saveQuietly();
                }
                $this->line("  · #{$post->id} already at source cap — marked researched");
                continue;
            }

            $extra = $researcher->research($post->title, $hosts, $room);

            if ($dry) {
                $names = collect($extra)->pluck('name')->implode(', ');
                $this->line("  ~ #{$post->id} " . Str::limit($post->title, 45) . " → found: " . ($names ?: 'none'));
                continue;
            }

            $mergedAny = false;
            foreach ($extra as $src) {
                $merged = $rewriter->mergeSource($post->title, (string) $post->body, $src['text'], $src['name']);
                // Only accept a merge that added content (never shrinks the article).
                if ($merged && str_word_count(strip_tags($merged)) >= str_word_count(strip_tags((string) $post->body))) {
                    $post->body = $merged;
                    $sources[] = ['name' => $src['name'], 'url' => $src['url']];
                    $mergedAny = true;
                    $added++;
                }
            }

            $post->forceFill(['sources' => $sources, 'web_researched_at' => now()])->saveQuietly();
            if ($mergedAny) {
                try {
                    $seo->optimizePost($post);
                } catch (\Throwable) {
                }
                $this->info("  ✓ #{$post->id} " . Str::limit($post->title, 45) . " — +" . count($extra) . " outlet(s), now " . count($sources) . " sources");
            } else {
                $this->line("  · #{$post->id} " . Str::limit($post->title, 45) . " — no new coverage found");
            }
        }

        $this->newLine();
        $this->info(($dry ? '[DRY] ' : '') . "Done — merged {$added} web source(s) across {$posts->count()} post(s).");

        return self::SUCCESS;
    }
}
