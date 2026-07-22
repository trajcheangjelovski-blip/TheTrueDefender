<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Processes admin-uploaded images: optional TheTrueDefender watermark, then
 * compresses to at most ~300 KB (downscaling + JPEG quality). Always outputs
 * JPEG for predictable size.
 */
class ImageProcessor
{
    /** Target ceiling for stored uploads. */
    public const MAX_BYTES = 300 * 1024;

    /** Longest edge we keep — plenty for the site, keeps files small. */
    private const MAX_DIMENSION = 1600;

    /**
     * Process an uploaded file and store it under $dir. Returns the stored path.
     *
     * @param  \Illuminate\Http\UploadedFile|\Livewire\Features\SupportFileUploads\TemporaryUploadedFile  $file
     */
    public function storeUpload($file, string $dir, bool $watermark, bool $preserveAlpha = false): string
    {
        $bytes = @file_get_contents($file->getRealPath()) ?: '';
        $info = @getimagesizefromstring($bytes);
        // Keep transparency (PNG) when asked and the source actually is a PNG —
        // e.g. product cut-outs. Otherwise flatten to a lean JPEG.
        $keepPng = $preserveAlpha && (($info['mime'] ?? '') === 'image/png');

        $out = $this->process($bytes, $watermark, $keepPng);
        $ext = $keepPng ? 'png' : 'jpg';

        $path = trim($dir, '/') . '/' . Str::random(24) . '.' . $ext;
        Storage::disk('public')->put($path, $out);

        return $path;
    }

    /**
     * Re-process an already-stored image in place (used by a maintenance command).
     */
    public function compressStored(string $path, bool $watermark = false): bool
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return false;
        }
        $jpeg = $this->process($disk->get($path), $watermark);
        $disk->put($path, $jpeg);

        return true;
    }

    /**
     * Take raw image bytes → watermarked (optional), compressed output.
     * When $keepPng is true, transparency is preserved and the result is PNG;
     * otherwise the image is flattened onto white and returned as JPEG.
     */
    public function process(string $bytes, bool $watermark, bool $keepPng = false): string
    {
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return $bytes; // not a raster image we can handle — store as-is
        }

        // Transparency-preserving path (product cut-outs): keep the alpha, no flatten.
        if ($keepPng) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            if (max(imagesx($img), imagesy($img)) > self::MAX_DIMENSION) {
                $img = $this->scaleAlpha($img, self::MAX_DIMENSION);
            }
            if ($watermark) {
                $this->stamp($img);
            }

            return $this->encodePng($img);
        }

        // Flatten onto white (handles PNG/GIF transparency for JPEG output).
        $w = imagesx($img);
        $h = imagesy($img);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $canvas;

        // Downscale to the max longest-edge.
        if (max($w, $h) > self::MAX_DIMENSION) {
            $img = $this->scale($img, self::MAX_DIMENSION);
        }

        if ($watermark) {
            $this->stamp($img);
        }

        return $this->encodeUnderLimit($img);
    }

    /** Scale so the longest edge equals $max, preserving aspect ratio. */
    private function scale(\GdImage $img, int $max): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = $max / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $dst;
    }

    /** Scale preserving the alpha channel (for transparent PNGs). */
    private function scaleAlpha(\GdImage $img, int $max): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = $max / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $dst;
    }

    /** Encode as PNG (transparency kept) under MAX_BYTES, shrinking if needed. */
    private function encodePng(\GdImage $img): string
    {
        for ($round = 0; $round < 5; $round++) {
            ob_start();
            imagepng($img, null, 9);
            $data = ob_get_clean();
            if (strlen($data) <= self::MAX_BYTES) {
                imagedestroy($img);

                return $data;
            }
            $img = $this->scaleAlpha($img, (int) round(max(imagesx($img), imagesy($img)) * 0.85));
        }

        ob_start();
        imagepng($img, null, 9);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    /** Encode as JPEG under MAX_BYTES: drop quality, then dimensions if needed. */
    private function encodeUnderLimit(\GdImage $img): string
    {
        for ($round = 0; $round < 6; $round++) {
            for ($q = 85; $q >= 40; $q -= 9) {
                ob_start();
                imagejpeg($img, null, $q);
                $data = ob_get_clean();
                if (strlen($data) <= self::MAX_BYTES) {
                    imagedestroy($img);

                    return $data;
                }
            }
            // Still too big — shrink 20% and try again.
            $img = $this->scale($img, (int) round(max(imagesx($img), imagesy($img)) * 0.8));
        }

        ob_start();
        imagejpeg($img, null, 40);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    /** Stamp a subtle "TheTrueDefender" wordmark in the bottom-right corner. */
    private function stamp(\GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $font = resource_path('fonts/brand.ttf');
        $text = 'TheTrueDefender';

        $size = max(13, (int) round($w * 0.026));
        $pad = (int) round($w * 0.02);

        if (is_file($font)) {
            $box = imagettfbbox($size, 0, $font, $text);
            $tw = $box[2] - $box[0];
            $th = $box[1] - $box[7];
            $x = $w - $tw - $pad;
            $y = $h - $pad;

            // Shadow for legibility on any background, then the white wordmark.
            $shadow = imagecolorallocatealpha($img, 0, 0, 0, 70);
            $white = imagecolorallocatealpha($img, 255, 255, 255, 25);
            imagettftext($img, $size, 0, $x + 2, $y + 2, $shadow, $font, $text);
            imagettftext($img, $size, 0, $x, $y, $white, $font, $text);
        } else {
            // Fallback: built-in bitmap font.
            $white = imagecolorallocate($img, 255, 255, 255);
            imagestring($img, 5, $w - imagefontwidth(5) * strlen($text) - $pad, $h - imagefontheight(5) - $pad, $text, $white);
        }
    }
}
