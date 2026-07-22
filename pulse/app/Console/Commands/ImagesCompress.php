<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ImageProcessor;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImagesCompress extends Command
{
    protected $signature = 'images:compress {--dry : Report only, change nothing} {--min-kb=300 : Only recompress files larger than this}';

    protected $description = 'Compress stored post/product base images to lean JPEGs (regenerates post variants).';

    public function handle(ImageProcessor $processor, ImageService $images): int
    {
        $disk = Storage::disk('public');
        $minBytes = ((int) $this->option('min-kb')) * 1024;
        $dry = (bool) $this->option('dry');

        $before = $after = 0;
        $done = $skipped = 0;

        // [model, column, isPost]
        $targets = [
            [Post::whereNotNull('featured_image')->get(['id', 'featured_image']), 'featured_image', true],
            [Product::whereNotNull('image')->get(['id', 'image']), 'image', false],
            [ProductVariant::whereNotNull('image')->get(['id', 'image']), 'image', false],
        ];

        foreach ($targets as [$rows, $col, $isPost]) {
            foreach ($rows as $model) {
                $path = $model->{$col};
                if (! $disk->exists($path)) {
                    $skipped++;
                    continue;
                }

                $size = $disk->size($path);
                if ($size <= $minBytes) {
                    $skipped++;
                    continue;
                }

                $before += $size;

                if ($dry) {
                    $after += min($size, $minBytes); // rough estimate for the preview
                    $done++;
                    continue;
                }

                // Recompress the base to a lean JPEG.
                $jpeg = $processor->process($disk->get($path), false);
                $stem = preg_replace('/\.[^.]+$/', '', $path);
                $newPath = $stem . '.jpg';

                $disk->put($newPath, $jpeg);
                if ($newPath !== $path) {
                    $disk->delete($path);           // remove the old (e.g. .png)
                    $model->forceFill([$col => $newPath])->saveQuietly();
                }
                $after += strlen($jpeg);

                // Rebuild the cropped variants from the new base (posts only).
                if ($isPost) {
                    $images->makeVariants($newPath);
                }

                $done++;
            }
        }

        $mb = fn ($b) => round($b / 1048576, 1) . ' MB';
        $this->info(($dry ? '[DRY RUN] ' : '')
            . "Compressed {$done} base image(s), skipped {$skipped}. "
            . "Bases: {$mb($before)} -> {$mb($after)} (saved " . $mb(max(0, $before - $after)) . ').');

        return self::SUCCESS;
    }
}
