<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Structured (JSON-schema) chat calls for the editorial pipeline, with token
 * usage recorded per call so each article's AI cost can be reported.
 *
 * Usage accumulates on this instance; the pipeline reads it with usage() and
 * stores it on the post / revision (ai_usage). Optional settings
 * ai_price_input_per_mtok / ai_price_output_per_mtok (USD per 1M tokens) turn the
 * token counts into an estimated cost.
 */
class AiClient
{
    private array $usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'by_stage' => []];

    public function enabled(): bool
    {
        return filled(Setting::get('openai_key', config('services.openai.key')));
    }

    /**
     * One JSON-schema call. $properties/$required describe the top-level object
     * (strict mode: every property required, no extras). Returns the decoded
     * object, or null on any failure.
     */
    public function json(string $stage, string $system, string $user, array $properties, ?string $model = null, int $timeout = 150): ?array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            return null;
        }
        $model = $model ?: Setting::get('openai_model', config('services.openai.model'));

        try {
            set_time_limit($timeout + 30);
            $response = Http::withToken(trim($key))->timeout($timeout)
                ->retry(2, 1500, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => $stage,
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => $properties,
                                'required' => array_keys($properties),
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ])->throw();

            $this->record($stage, (array) $response->json('usage', []));
            $data = json_decode((string) data_get($response->json(), 'choices.0.message.content', ''), true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning("AI call [{$stage}] failed: " . $e->getMessage());

            return null;
        }
    }

    /** Token usage so far, plus an estimated USD cost when prices are configured. */
    public function usage(): array
    {
        $in = (float) Setting::get('ai_price_input_per_mtok', 0);
        $out = (float) Setting::get('ai_price_output_per_mtok', 0);
        $u = $this->usage;
        $u['estimated_usd'] = ($in > 0 || $out > 0)
            ? round(($u['prompt_tokens'] * $in + $u['completion_tokens'] * $out) / 1_000_000, 4)
            : null;

        return $u;
    }

    public function reset(): void
    {
        $this->usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'by_stage' => []];
    }

    private function record(string $stage, array $u): void
    {
        $p = (int) ($u['prompt_tokens'] ?? 0);
        $c = (int) ($u['completion_tokens'] ?? 0);
        $this->usage['calls']++;
        $this->usage['prompt_tokens'] += $p;
        $this->usage['completion_tokens'] += $c;
        $this->usage['by_stage'][$stage] = [
            'calls' => ($this->usage['by_stage'][$stage]['calls'] ?? 0) + 1,
            'tokens' => ($this->usage['by_stage'][$stage]['tokens'] ?? 0) + $p + $c,
        ];
    }
}
