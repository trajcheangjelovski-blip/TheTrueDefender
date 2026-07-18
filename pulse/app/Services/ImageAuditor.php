<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detects source-branded photos (e.g. a "BBC NEWS" watermark baked into a
 * copied press image) using the vision model, and replaces them with an
 * original AI-generated image. We regenerate rather than erase watermarks —
 * removing another outlet's logo to republish their copyrighted photo is a
 * copyright problem, not a fix.
 */
class ImageAuditor
{
    public function __construct(private ImageService $images) {}

    /**
     * Ask the vision model whether an image carries a watermark / logo /
     * network branding. Fail-safe: returns false on any error so we never
     * destroy an image because of an API hiccup.
     *
     * @return array{branded:bool, detail:string}
     */
    public function inspect(string $relativePath): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        $abs = Storage::disk('public')->path($relativePath);

        if (blank($key) || ! is_file($abs)) {
            return ['branded' => false, 'detail' => 'skipped (no key or missing file)'];
        }

        try {
            set_time_limit(120);
            $mime = str_ends_with($abs, '.png') ? 'image/png' : 'image/jpeg';
            $b64 = base64_encode(file_get_contents($abs));

            $response = Http::withToken(trim($key))
                ->timeout(60)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' =>
                                'Does this image contain a visible watermark, logo, channel bug, or '
                                . 'news-network branding (e.g. "BBC NEWS", "REUTERS", "AP")? Look at all corners.'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$b64}"]],
                        ],
                    ]],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'watermark_check',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'branded' => ['type' => 'boolean'],
                                    'detail' => ['type' => 'string'],
                                ],
                                'required' => ['branded', 'detail'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])
                ->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);

            return [
                'branded' => (bool) ($data['branded'] ?? false),
                'detail' => (string) ($data['detail'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('Image watermark inspection failed: ' . $e->getMessage());

            return ['branded' => false, 'detail' => 'inspection failed'];
        }
    }

    /**
     * Inspect a post's image; if branded, replace it with an original AI image.
     *
     * @return array{action:string, detail:string}
     */
    public function rebrandPost(Post $post): array
    {
        if (blank($post->featured_image)) {
            return ['action' => 'no-image', 'detail' => ''];
        }

        $check = $this->inspect($post->featured_image);
        if (! $check['branded']) {
            return ['action' => 'clean', 'detail' => $check['detail']];
        }

        $new = $this->images->generate(
            "Editorial news illustration for a {$post->category?->name} story titled: "
            . "{$post->title}. Photorealistic, tasteful, no text, no logos, no watermarks."
        );

        if (blank($new)) {
            return ['action' => 'branded-but-failed', 'detail' => $check['detail']];
        }

        $old = $post->featured_image;
        $post->forceFill(['featured_image' => $new])->saveQuietly();
        $this->deleteImageAndVariants($old);

        return ['action' => 'replaced', 'detail' => $check['detail']];
    }

    /** Remove an image file plus its hero/card/thumb variants. */
    private function deleteImageAndVariants(string $path): void
    {
        $disk = Storage::disk('public');
        $stem = preg_replace('/\.[^.]+$/', '', $path);
        foreach ([$path, "{$stem}-hero.jpg", "{$stem}-card.jpg", "{$stem}-thumb.jpg"] as $file) {
            if ($disk->exists($file)) {
                $disk->delete($file);
            }
        }
    }
}
