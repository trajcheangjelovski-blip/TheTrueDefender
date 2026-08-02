<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TagGenerator
{
    /**
     * Suggest 3-5 evergreen topic labels for an existing article. Returns an
     * empty array on any failure (no key, quota, network) so backfill is safe.
     *
     * @return array<int,string>
     */
    public function suggest(string $title, ?string $body): array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return [];
        }

        try {
            set_time_limit(60);
            $response = Http::withToken(trim($key))->timeout(30)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'Return 3 to 5 evergreen TOPIC labels for this news article — the durable '
                            . 'subjects a reader would follow over time (people, places, organizations, '
                            . 'ongoing issues), NOT one-off specifics. Title Case, 1-3 words each, no '
                            . 'hashtags. Prefer broad labels many future stories could also share.'],
                        ['role' => 'user', 'content' => 'TITLE: ' . $title . "\n\n" . Str::limit(strip_tags((string) $body), 2000, '')],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'tags',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                                'required' => ['tags'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);

            return array_values(array_filter(array_map('strval', (array) ($data['tags'] ?? []))));
        } catch (\Throwable $e) {
            Log::warning('Tag suggestion failed: ' . $e->getMessage());

            return [];
        }
    }
}
