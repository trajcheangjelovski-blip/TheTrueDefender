<?php

namespace App\Jobs;

use App\Models\PageSeo;
use App\Models\Post;
use App\Services\SeoOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OptimizeSeoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    /** @param 'post'|'page' $type */
    public function __construct(
        public string $type,
        public int $id,
        public bool $overwrite = false,
    ) {}

    public function handle(SeoOptimizer $optimizer): void
    {
        try {
            if ($this->type === 'post') {
                $post = Post::find($this->id);
                if ($post) {
                    $optimizer->optimizePost($post, $this->overwrite);
                }
            } else {
                $page = PageSeo::find($this->id);
                if ($page) {
                    $optimizer->optimizePage($page, $this->overwrite);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("SEO optimize job failed ({$this->type} #{$this->id}): " . $e->getMessage());
            throw $e;
        }
    }
}
