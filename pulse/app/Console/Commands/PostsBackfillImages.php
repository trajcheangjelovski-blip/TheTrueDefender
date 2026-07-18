<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ImageService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('posts:backfill-images {--limit=3 : Max posts to fix per run} {--all : Include posts without a source (e.g. demo/manual)}')]
#[Description('Self-healing: find published posts with a missing/broken featured image and generate one')]
class PostsBackfillImages extends Command
{
    public function handle(ImageService $images): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = Post::where('status', 'published');
        if (! $this->option('all')) {
            $query->whereNotNull('source_url'); // real ingested articles only by default
        }

        // A post needs healing if it has no image, or its image file is gone.
        $missing = $query->get(['id', 'title', 'featured_image', 'category_id'])
            ->filter(fn (Post $p) => blank($p->featured_image)
                || ! Storage::disk('public')->exists($p->featured_image))
            ->take($limit);

        if ($missing->isEmpty()) {
            $this->info('No posts need an image.');

            return self::SUCCESS;
        }

        foreach ($missing as $post) {
            try {
                $path = $images->generate(
                    'Editorial news illustration for a ' . ($post->category?->name ?? 'news')
                    . ' story titled: ' . $post->title
                    . '. Photorealistic, tasteful, no text, no logos, no watermarks.'
                );
                if ($path) {
                    $post->forceFill(['featured_image' => $path])->saveQuietly();
                    $this->info("#{$post->id} image generated.");
                } else {
                    $this->warn("#{$post->id} generation returned null (will retry next run).");
                }
            } catch (\Throwable $e) {
                Log::warning("Backfill image failed for post {$post->id}: " . $e->getMessage());
                $this->error("#{$post->id} failed: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
