<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The short AI caption used for social posts (posted before the link). Ingested
 * posts already get one from the rewrite; this fills it in on demand for manual
 * or older posts, caching the result on the post so it's generated only once.
 */
class SocialCaptionService
{
    public function for(Post $post): string
    {
        if (filled($post->social_text)) {
            return $post->social_text;
        }

        $caption = $this->generate($post) ?: Str::limit(strip_tags($post->excerpt ?: $post->title), 180, '');

        // Cache it on the post so we don't regenerate on every channel/retry.
        $post->forceFill(['social_text' => $caption])->saveQuietly();

        return $caption;
    }

    private function generate(Post $post): ?string
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return null;
        }

        try {
            set_time_limit(60);
            $response = Http::withToken(trim($key))
                ->timeout(30)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'Write ONE punchy social-media caption (max 180 characters) to promote this news '
                            . 'article and make people want to click. No hashtags, no URL, no quotation marks; '
                            . 'at most one emoji. Return only the caption text.'],
                        ['role' => 'user', 'content' =>
                            'HEADLINE: ' . $post->title . "\n\n" . Str::limit(strip_tags($post->excerpt ?? ''), 400, '')],
                    ],
                ])->throw();

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            $text = trim($text, " \t\n\r\0\x0B\"'");

            return $text !== '' ? Str::limit($text, 280, '') : null;
        } catch (\Throwable $e) {
            Log::warning('Social caption generation failed: ' . $e->getMessage());

            return null;
        }
    }
}
