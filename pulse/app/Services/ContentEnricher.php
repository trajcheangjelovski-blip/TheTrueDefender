<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContentEnricher
{
    /**
     * Generate Key Takeaways + FAQ for an existing article. Returns
     * ['takeaways' => string[], 'faqs' => [{question,answer}]]. Empty on any
     * failure (no key, quota, network) so backfill is always safe.
     *
     * @return array{takeaways:array<int,string>,faqs:array<int,array{question:string,answer:string}>}
     */
    public function enrich(string $title, ?string $body): array
    {
        $empty = ['takeaways' => [], 'faqs' => []];

        $key = Setting::get('openai_key', config('services.openai.key'));
        $text = trim(strip_tags((string) $body));
        if (blank($key) || Str::wordCount($text) < 60) {
            return $empty;
        }

        try {
            set_time_limit(90);
            $response = Http::withToken(trim($key))->timeout(60)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => Setting::get('openai_model', config('services.openai.model')),
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            "From the article below, produce:\n"
                            . "- takeaways: 3-4 one-sentence bullets (≤22 words) capturing the most important "
                            . "facts (\"the bottom line\"). Self-contained, grounded ONLY in the article.\n"
                            . "- faqs: 3-4 real questions a reader would ask about THIS story, each with a "
                            . "concise 1-3 sentence answer grounded ONLY in the article's facts. No filler "
                            . "questions. Omit any question the article can't answer.\n"
                            . 'Never invent facts, quotes, numbers, or names. Write plainly, no meta-commentary.'],
                        ['role' => 'user', 'content' => 'TITLE: ' . $title . "\n\n" . Str::limit($text, 6000, '')],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'enrichment',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'takeaways' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'faqs' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'question' => ['type' => 'string'],
                                                'answer' => ['type' => 'string'],
                                            ],
                                            'required' => ['question', 'answer'],
                                            'additionalProperties' => false,
                                        ],
                                    ],
                                ],
                                'required' => ['takeaways', 'faqs'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);

            return [
                'takeaways' => Rewriter::cleanTakeaways($data['takeaways'] ?? []),
                'faqs' => Rewriter::cleanFaqs($data['faqs'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::warning('Content enrichment failed: ' . $e->getMessage());

            return $empty;
        }
    }
}
