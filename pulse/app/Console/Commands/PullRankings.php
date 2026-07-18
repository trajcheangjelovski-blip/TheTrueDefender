<?php

namespace App\Console\Commands;

use App\Models\PageSeo;
use App\Models\Post;
use App\Services\SearchConsole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seo:pull-rankings {--days=28 : Search Console window in days}')]
#[Description('Pull real Google ranking data (position/clicks/impressions) from Search Console into posts & pages')]
class PullRankings extends Command
{
    public function handle(SearchConsole $gsc): int
    {
        if (! $gsc->isConfigured()) {
            $this->warn('Search Console is not configured (add a service-account JSON + property in AI & Ads Settings). Skipping.');

            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        // Search Console data lags ~3 days; end the window there.
        $end = now()->subDays(3)->toDateString();
        $start = now()->subDays(3 + $days)->toDateString();

        $this->info("Fetching Search Console page metrics {$start} → {$end}…");

        try {
            $rows = $gsc->pageMetrics($start, $end);
        } catch (\Throwable $e) {
            $this->error('Search Console request failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Index metrics by normalized path so we can match posts & pages.
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[$this->normalize($row['page'])] = $row;
        }

        $posts = $this->syncPosts($byPath);
        $pages = $this->syncPages($byPath);

        $this->info("Updated {$posts} post(s) and {$pages} page(s) from " . count($rows) . ' Search Console rows.');

        return self::SUCCESS;
    }

    private function syncPosts(array $byPath): int
    {
        $count = 0;
        Post::query()->select(['id', 'slug'])->chunkById(200, function ($posts) use ($byPath, &$count) {
            foreach ($posts as $post) {
                $path = $this->normalize(route('post.show', $post->slug));
                if ($m = ($byPath[$path] ?? null)) {
                    $post->forceFill($this->metricFields($m))->save();
                    $count++;
                }
            }
        });

        return $count;
    }

    private function syncPages(array $byPath): int
    {
        PageSeo::ensureSeeded();
        $count = 0;
        foreach (PageSeo::all() as $page) {
            $path = $this->normalize(url($page->path));
            if ($m = ($byPath[$path] ?? null)) {
                $page->forceFill($this->metricFields($m))->save();
                $count++;
            }
        }

        return $count;
    }

    private function metricFields(array $m): array
    {
        return [
            'gsc_position' => $m['position'],
            'gsc_clicks' => $m['clicks'],
            'gsc_impressions' => $m['impressions'],
            'gsc_ctr' => $m['ctr'],
            'gsc_synced_at' => now(),
        ];
    }

    /** Strip scheme/host/trailing slash so GSC URLs match our routes. */
    private function normalize(string $url): string
    {
        return rtrim(parse_url($url, PHP_URL_PATH) ?? '/', '/') ?: '/';
    }
}
