<?php

namespace App\Console\Commands;

use App\Models\PageSeo;
use App\Models\Post;
use App\Services\SeoOptimizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seo:optimize {--pages : Optimize the static pages} {--posts : Optimize all posts} {--force : Overwrite existing meta with AI suggestions} {--missing-only : Only posts never analyzed}')]
#[Description('AI SEO optimization: analyze, apply suggested meta, and re-score pages and/or posts')]
class SeoOptimize extends Command
{
    public function handle(SeoOptimizer $optimizer): int
    {
        $doPages = $this->option('pages');
        $doPosts = $this->option('posts');
        if (! $doPages && ! $doPosts) {
            $doPages = $doPosts = true; // no flags = full site
        }
        $overwrite = (bool) $this->option('force');

        if ($doPages) {
            PageSeo::ensureSeeded();
            foreach (PageSeo::all() as $page) {
                try {
                    $a = $optimizer->optimizePage($page, $overwrite);
                    $this->info("page {$page->key}: {$a['score']}/100 ({$a['grade']})");
                } catch (\Throwable $e) {
                    $this->error("page {$page->key} failed: " . $e->getMessage());
                }
            }
        }

        if ($doPosts) {
            $query = Post::query();
            if ($this->option('missing-only')) {
                $query->whereNull('seo_analyzed_at');
            }
            $query->orderBy('id')->chunkById(50, function ($posts) use ($optimizer, $overwrite) {
                foreach ($posts as $post) {
                    try {
                        $a = $optimizer->optimizePost($post, $overwrite);
                        $this->info("post #{$post->id}: {$a['score']}/100 — " . str($post->title)->limit(50));
                    } catch (\Throwable $e) {
                        $this->error("post #{$post->id} failed: " . $e->getMessage());
                    }
                }
            });
        }

        $this->info('SEO optimization complete.');

        return self::SUCCESS;
    }
}
