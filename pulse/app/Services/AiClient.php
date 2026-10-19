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
    private array $usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'by_stage' => [], 'by_model' => [], 'usd' => 0.0, 'unpriced_tokens' => 0];

    private int $attempts = 0;

    /** Why the last call returned null (no key, refused, HTTP error, truncated, unparseable) — for the article's record. */
    private ?string $lastError = null;

    /** Optional limits for unpublished previews; normal publication is unchanged. */
    public function __construct(
        private ?int $maxCalls = null,
        private ?int $maxCompletionTokens = null,
        private ?int $maxInputBytes = null,
        /** Hard USD ceiling for this client: a call is refused unless its worst-case cost still fits. */
        private ?float $maxCostUsd = null,
        private float $inputUsdPerMtok = 0.75,    // gpt-5.4-mini standard, developers.openai.com/api/docs/pricing (checked 2026-10-18)
        private float $outputUsdPerMtok = 4.50,
    ) {}

    /** Conservative worst case for one call: ~3 bytes per input token (no cache discount), full output cap (reasoning tokens count toward it). */
    private function worstCaseUsd(int $inputBytes, ?string $model = null): float
    {
        // The model about to be called may cost more than the constructor's default (gpt-5.4 is 3.3x gpt-5.4-mini).
        $p = \App\Support\AiModels::price($model ?: \App\Support\AiModels::writer());
        $in = max($this->inputUsdPerMtok, (float) ($p[0] ?? 0));
        $out = max($this->outputUsdPerMtok, (float) ($p[2] ?? 0));

        return ($inputBytes / 3) * $in / 1_000_000 + (int) $this->maxCompletionTokens * $out / 1_000_000;
    }

    /** What this client has spent: the larger of the default-price estimate and the per-model recorded cost (which prices each model correctly). */
    private function spentUsd(): float
    {
        $default = ($this->usage['prompt_tokens'] * $this->inputUsdPerMtok + $this->usage['completion_tokens'] * $this->outputUsdPerMtok) / 1_000_000;

        return max($default, (float) $this->usage['usd']);
    }

    public function enabled(): bool
    {
        return filled(Setting::get('openai_key', config('services.openai.key')));
    }

    /**
     * One JSON-schema call. $properties/$required describe the top-level object
     * (strict mode: every property required, no extras). Returns the decoded
     * object, or null on any failure.
     */
    public function json(string $stage, string $system, string $user, array $properties, ?string $model = null, int $timeout = 150, ?string $reasoningEffort = null): ?array
    {
        $this->lastError = null;
        if ($this->maxCalls !== null && $this->attempts >= $this->maxCalls) {
            $this->lastError = 'call limit reached';

            return null;
        }
        if ($this->maxInputBytes !== null && strlen($system . $user . json_encode($properties)) > $this->maxInputBytes) {
            Log::warning("AI call [{$stage}] refused: input exceeds the {$this->maxInputBytes}-byte limit.");
            $this->lastError = 'input too large';

            return null;
        }
        if ($this->maxCostUsd !== null) {
            $bytes = strlen($system . $user . json_encode($properties));
            if ($this->maxCompletionTokens === null
                || $this->spentUsd() + $this->worstCaseUsd($bytes, $model) > $this->maxCostUsd) {
                Log::warning("AI call [{$stage}] refused: it could exceed the \${$this->maxCostUsd} cost ceiling.");
                $this->lastError = 'cost ceiling';

                return null;
            }
        }
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key)) {
            $this->lastError = 'no OpenAI key configured';

            return null;
        }
        $model = $model ?: \App\Support\AiModels::writer();

        try {
            $this->attempts++;
            set_time_limit($timeout + 30);
            $response = Http::withToken(trim($key))->timeout($timeout)
                ->retry($this->maxCalls === null ? 2 : 1, 1500, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    // GPT-5.x reasoning models: research / fact-check send an effort level
                    // (verified against Chat Completions 2026-10-19); the writer sends none.
                    ...($reasoningEffort ? ['reasoning_effort' => $reasoningEffort] : []),
                    ...($this->maxCompletionTokens === null ? [] : ['max_completion_tokens' => $this->maxCompletionTokens]),
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

            $this->record($stage, (array) $response->json('usage', []), (string) ($response->json('model') ?: $model));
            if (data_get($response->json(), 'choices.0.finish_reason') === 'length') {
                $this->lastError = "{$model}: output was cut off (length)";

                return null; // an output cap must never turn a truncated preview into an article
            }
            $data = json_decode((string) data_get($response->json(), 'choices.0.message.content', ''), true);
            if (! is_array($data)) {
                $this->lastError = "{$model}: unparseable output";
            }

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            // The message never contains the API key (it is sent only as a header).
            $this->lastError = "{$model}: " . \Illuminate\Support\Str::limit($e->getMessage(), 200);
            Log::warning("AI call [{$stage}] failed: " . $e->getMessage());

            return null;
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Token usage so far, by stage and by model, with the estimated USD cost at
     * each model's own price (AiModels::PRICES, cached input discounted).
     * Calls to a model without a known price use ai_price_*_per_mtok if set.
     */
    public function usage(): array
    {
        $u = $this->usage;
        $usd = $u['usd'];
        if ($u['unpriced_tokens'] > 0) {
            $in = (float) Setting::get('ai_price_input_per_mtok', 0);
            $out = (float) Setting::get('ai_price_output_per_mtok', 0);
            $usd = ($in > 0 || $out > 0) ? $usd + $u['unpriced_tokens'] * max($in, $out) / 1_000_000 : null;
        }
        $u['estimated_usd'] = $usd === null ? null : round($usd, 4);
        unset($u['usd'], $u['unpriced_tokens']);

        if ($this->maxCostUsd !== null) {
            $u['test_cost_usd'] = round($this->spentUsd(), 5);   // at the constructor's verified prices
            $u['cost_ceiling_usd'] = $this->maxCostUsd;
        }

        return $u;
    }

    public function reset(): void
    {
        $this->usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'by_stage' => [], 'by_model' => [], 'usd' => 0.0, 'unpriced_tokens' => 0];
    }

    private function record(string $stage, array $u, string $model): void
    {
        $p = (int) ($u['prompt_tokens'] ?? 0);
        $c = (int) ($u['completion_tokens'] ?? 0);           // includes reasoning tokens
        $cached = min($p, (int) data_get($u, 'prompt_tokens_details.cached_tokens', 0));
        $price = \App\Support\AiModels::price($model);
        $usd = $price ? (($p - $cached) * $price[0] + $cached * $price[1] + $c * $price[2]) / 1_000_000 : 0.0;
        if (! $price) {
            $this->usage['unpriced_tokens'] += $p + $c;
        }

        $this->usage['calls']++;
        $this->usage['prompt_tokens'] += $p;
        $this->usage['completion_tokens'] += $c;
        $this->usage['usd'] += $usd;
        $s = $this->usage['by_stage'][$stage] ?? ['calls' => 0, 'tokens' => 0, 'usd' => 0.0];
        $this->usage['by_stage'][$stage] = [
            'calls' => $s['calls'] + 1,
            'tokens' => $s['tokens'] + $p + $c,
            'model' => $model,
            'usd' => round(($s['usd'] ?? 0) + $usd, 5),
        ];
        $m = $this->usage['by_model'][$model] ?? ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'reasoning_tokens' => 0, 'usd' => 0.0];
        $this->usage['by_model'][$model] = [
            'calls' => $m['calls'] + 1,
            'prompt_tokens' => $m['prompt_tokens'] + $p,
            'completion_tokens' => $m['completion_tokens'] + $c,
            'reasoning_tokens' => $m['reasoning_tokens'] + (int) data_get($u, 'completion_tokens_details.reasoning_tokens', 0),
            'usd' => round($m['usd'] + $usd, 5),
        ];
    }
}
