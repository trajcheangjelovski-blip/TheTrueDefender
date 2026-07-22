<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    private const MAX_BYTES = 6_000_000; // 6 MB

    /**
     * Sources narrower than this are junk (e.g. 240px RSS thumbnails) and
     * are rejected — the pipeline then falls back to AI generation.
     */
    public const MIN_SOURCE_WIDTH = 500;

    /**
     * The site's image size rules — every stored image gets these 16:9
     * variants, and each placement uses the right one:
     *   hero  → homepage slider + article page hero
     *   card  → category feature/overlay cards, related-story cards
     *   thumb → small list rows and mini thumbnails
     */
    public const VARIANTS = [
        'hero' => [1600, 900],
        'card' => [800, 450],
        'thumb' => [400, 225],
    ];

    /**
     * Download a remote image into public storage (with quality gate +
     * per-placement variants). Returns the stored relative path or null.
     */
    public function storeFromUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        try {
            $res = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'TheTrueDefender/1.0'])
                ->get($url);

            if (! $res->ok()) {
                return null;
            }

            $type = strtolower($res->header('Content-Type') ?? '');
            $body = $res->body();

            if (! str_starts_with($type, 'image/') || strlen($body) > self::MAX_BYTES || strlen($body) < 512) {
                return null;
            }

            // Quality gate: refuse tiny sources — they look terrible stretched
            // across cards. (The caller falls back to og:image or AI.)
            $dims = @getimagesizefromstring($body);
            if (! $dims || $dims[0] < self::MIN_SOURCE_WIDTH) {
                Log::info('Image rejected (too small: ' . ($dims[0] ?? '?') . 'px): ' . $url);

                return null;
            }

            // Compress the source photo to a lean JPEG base (variants crop from it).
            $jpeg = app(ImageProcessor::class)->process($body, false);
            $path = 'posts/' . Str::random(24) . '.jpg';
            Storage::disk('public')->put($path, $jpeg);
            $this->makeVariants($path);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Image download failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate the per-placement 16:9 crops (hero/card/thumb) for a stored
     * image. Center-crops to 16:9, resizes, saves as JPEG. Safe to re-run.
     */
    public function makeVariants(string $path): void
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return;
        }

        $src = @imagecreatefromstring($disk->get($path));
        if (! $src) {
            return;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Center-crop box at 16:9.
        $ratio = 16 / 9;
        if ($srcW / $srcH > $ratio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $ratio);
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $ratio);
        }
        $cropX = (int) (($srcW - $cropW) / 2);
        $cropY = (int) (($srcH - $cropH) / 2);

        $stem = preg_replace('/\.[^.]+$/', '', $path);

        foreach (self::VARIANTS as $name => [$w, $h]) {
            // Never upscale beyond the cropped source — cap at source size.
            if ($cropW < $w) {
                $h = (int) round($cropW / $ratio);
                $w = $cropW;
            }

            $dst = imagecreatetruecolor($w, $h);
            imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);

            // NOTE: the brand mark is applied as a CSS overlay (.img-logo) at
            // display time, NOT baked into the pixels. We deliberately do NOT
            // stamp text here — baking + the CSS overlay produced a double
            // watermark, so the pixel watermark has been retired.

            ob_start();
            imagejpeg($dst, null, 82);
            $disk->put("{$stem}-{$name}.jpg", ob_get_clean());
            imagedestroy($dst);
        }

        imagedestroy($src);
    }

    /**
     * Generate an original image with OpenAI from a text prompt.
     * Returns the stored relative path or null on failure / no key.
     */
    public function generate(string $prompt): ?string
    {
        $key = \App\Models\Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return null;
        }

        try {
            set_time_limit(300); // image generation is slow — never hit the 30s web cap

            $model = \App\Models\Setting::get('openai_image_model', config('services.openai.image_model', 'dall-e-3'));

            // Widescreen output to match the site's 16:9 card crops. The two
            // model families take different parameters:
            //  - dall-e-*    → 1792x1024, must request b64 via response_format
            //  - gpt-image-* → 1536x1024, no response_format (always base64)
            $params = [
                'model' => $model,
                'prompt' => Str::limit($prompt, 900, ''),
                'n' => 1,
            ];
            if (str_starts_with($model, 'dall-e')) {
                $params['size'] = '1792x1024';
                $params['response_format'] = 'b64_json';
            } else {
                $params['size'] = '1536x1024';
            }

            $res = Http::withToken(trim($key))
                ->timeout(180)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/images/generations', $params)
                ->throw()
                ->json();

            $b64 = data_get($res, 'data.0.b64_json');
            if (blank($b64)) {
                return null;
            }

            // Store the AI original as a lean JPEG (was a ~3 MB PNG). Variants are
            // cropped from it; no watermark on the base.
            $jpeg = app(ImageProcessor::class)->process(base64_decode($b64), false);
            $path = 'posts/' . Str::random(24) . '.jpg';
            Storage::disk('public')->put($path, $jpeg);
            $this->makeVariants($path);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('AI image generation failed: ' . $e->getMessage());
            return null;
        }
    }
}
