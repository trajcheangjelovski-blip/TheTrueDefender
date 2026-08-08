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

        [$cropX, $cropY, $cropW, $cropH] = $this->crop16x9($src);
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

            // Encode under 300 KB — drop quality if a crop would exceed it.
            $q = 82;
            do {
                ob_start();
                imagejpeg($dst, null, $q);
                $data = ob_get_clean();
                if (strlen($data) <= 300 * 1024 || $q <= 45) {
                    break;
                }
                $q -= 8;
            } while (true);
            $disk->put("{$stem}-{$name}.jpg", $data);
            imagedestroy($dst);
        }

        // Baked-in watermark for the SHARE image only (og:image). This travels
        // with reshares/downloads (unlike the on-site CSS overlay), so the brand
        // rides along on Truth Social/X/Facebook. Separate file → never doubles
        // up with the CSS overlay used for on-site display.
        $this->stampShare($src, $cropX, $cropY, $cropW, $cropH, $stem, $disk);

        imagedestroy($src);
    }

    /** Center-crop box at 16:9 for a GD image → [x, y, w, h]. */
    private function crop16x9($src): array
    {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $ratio = 16 / 9;
        if ($srcW / $srcH > $ratio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $ratio);
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $ratio);
        }

        return [(int) (($srcW - $cropW) / 2), (int) (($srcH - $cropH) / 2), $cropW, $cropH];
    }

    /** Build/refresh only the watermarked share image for an already-stored base. */
    public function ensureShareVariant(string $path): void
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return;
        }
        $src = @imagecreatefromstring($disk->get($path));
        if (! $src) {
            return;
        }
        [$x, $y, $w, $h] = $this->crop16x9($src);
        $this->stampShare($src, $x, $y, $w, $h, preg_replace('/\.[^.]+$/', '', $path), $disk);
        imagedestroy($src);
    }

    /** Create the 16:9 share image (hero-size) with the brand stamped in. */
    private function stampShare($src, int $cropX, int $cropY, int $cropW, int $cropH, string $stem, $disk): void
    {
        [$w, $h] = self::VARIANTS['hero']; // 1600x900
        if ($cropW < $w) {                 // never upscale past the source
            $w = $cropW;
            $h = (int) round($cropW * 9 / 16);
        }

        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $w, $h, $cropW, $cropH);
        $this->stampBrand($dst);

        $q = 85;
        do {
            ob_start();
            imagejpeg($dst, null, $q);
            $data = ob_get_clean();
            if (strlen($data) <= 500 * 1024 || $q <= 55) {
                break;
            }
            $q -= 8;
        } while (true);

        $disk->put("{$stem}-share.jpg", $data);
        imagedestroy($dst);
    }

    /**
     * Stamp the TheTrueDefender logo (red "TTD" badge + "The True Defender"
     * wordmark, Defender in accent red) top-left, with a soft shadow for
     * legibility. Matches the on-site CSS overlay. No-op if the font is missing.
     */
    private function stampBrand($img): void
    {
        $font = resource_path('fonts/brand.ttf');
        if (! is_file($font) || ! function_exists('imagettftext')) {
            return;
        }

        $w = imagesx($img);
        $s = $w / 1600;                     // scale relative to a 1600px share
        $pad = (int) round(30 * $s);
        $badge = (int) round(54 * $s);

        $red = imagecolorallocate($img, 227, 59, 78);       // --accent
        $white = imagecolorallocate($img, 255, 255, 255);
        $accent2 = imagecolorallocate($img, 255, 107, 125); // --accent-2
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 75);

        // Red badge with centered "TTD".
        $bx = $pad;
        $by = $pad;
        imagefilledrectangle($img, $bx, $by, $bx + $badge, $by + $badge, $red);
        $ttdSize = 15 * $s;
        $bb = imagettfbbox($ttdSize, 0, $font, 'TTD');
        $tx = $bx + (int) (($badge - ($bb[2] - $bb[0])) / 2);
        $ty = $by + (int) (($badge + ($bb[1] - $bb[7])) / 2);
        imagettftext($img, $ttdSize, 0, $tx, $ty, $white, $font, 'TTD');

        // Wordmark to the right, vertically centered with the badge.
        $wmSize = 23 * $s;
        $wx = $bx + $badge + (int) round(16 * $s);
        $wb = imagettfbbox($wmSize, 0, $font, 'The True Defender');
        $wy = $by + (int) (($badge + ($wb[1] - $wb[7])) / 2);

        // Soft shadow behind the whole wordmark, then two-tone text.
        $off = max(1, (int) round(2 * $s));
        imagettftext($img, $wmSize, 0, $wx + $off, $wy + $off, $shadow, $font, 'The True Defender');
        imagettftext($img, $wmSize, 0, $wx, $wy, $white, $font, 'The True ');
        $p1 = imagettfbbox($wmSize, 0, $font, 'The True ');
        imagettftext($img, $wmSize, 0, $wx + ($p1[2] - $p1[0]), $wy, $accent2, $font, 'Defender');
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
