<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImagesShare extends Command
{
    protected $signature = 'images:share {--limit=0 : Max posts (0 = all)} {--force : Rebuild even if a share image already exists}';

    protected $description = 'Generate the watermarked -share.jpg (social og:image) for existing posts.';

    public function handle(ImageService $images): int
    {
        $disk = Storage::disk('public');

        $query = Post::whereNotNull('featured_image');
        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }
        $posts = $query->latest('published_at')->get(['id', 'featured_image']);

        $done = $skipped = 0;
        $bar = $this->output->createProgressBar($posts->count());
        $bar->start();

        foreach ($posts as $post) {
            $share = preg_replace('/\.[^.]+$/', '', $post->featured_image) . '-share.jpg';
            if (! $this->option('force') && $disk->exists($share)) {
                $skipped++;
                $bar->advance();
                continue;
            }
            $images->ensureShareVariant($post->featured_image);
            $done++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated {$done} share image(s), skipped {$skipped} existing.");

        return self::SUCCESS;
    }
}
