<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which model does which job — three independent settings:
 *
 *   research_model   (default gpt-5.4, reasoning "medium")  — research analysis
 *   openai_model     (default gpt-5.4-mini)                  — article writing + utility calls
 *   fact_check_model (default gpt-5.4, reasoning "medium")   — independent fact-check
 *
 * Admin settings win; otherwise config/services.php (PULSE_AI_RESEARCH_MODEL,
 * PULSE_AI_MODEL, PULSE_AI_FACT_CHECK_MODEL). Research and fact-check NEVER fall
 * back to the writing model: if the configured model is unavailable the stage
 * fails and the article is held (see EditorialPipeline).
 *
 * PRICES: USD per 1M tokens [input, cached input, output], standard tier,
 * developers.openai.com/api/docs/pricing (checked 2026-10-19). Reasoning tokens
 * are billed as output.
 */
class AiModels
{
    public const PRICES = [
        'gpt-5.4' => [2.50, 0.25, 15.00],
        'gpt-5.4-mini' => [0.75, 0.075, 4.50],
        'gpt-5.5' => [5.00, 0.50, 30.00],
    ];

    public const EFFORTS = ['none', 'low', 'medium', 'high'];

    public static function research(): string
    {
        return (string) (Setting::get('research_model') ?: config('services.openai.research_model', 'gpt-5.4'));
    }

    public static function writer(): string
    {
        return (string) (Setting::get('openai_model') ?: config('services.openai.model', 'gpt-5.4-mini'));
    }

    public static function factCheck(): string
    {
        return (string) (Setting::get('fact_check_model') ?: config('services.openai.fact_check_model', 'gpt-5.4'));
    }

    public static function researchEffort(): ?string
    {
        return self::effort(Setting::get('research_reasoning_effort') ?: config('services.openai.research_reasoning', 'medium'));
    }

    public static function factCheckEffort(): ?string
    {
        return self::effort(Setting::get('fact_check_reasoning_effort') ?: config('services.openai.fact_check_reasoning', 'medium'));
    }

    /** "none"/unknown → null (no reasoning_effort parameter is sent). */
    private static function effort(mixed $value): ?string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, self::EFFORTS, true) && $v !== 'none' ? $v : null;
    }

    /** [input, cached input, output] USD per 1M tokens, matched by model family (dated snapshots included). */
    public static function price(string $model): ?array
    {
        $model = strtolower($model);
        $best = null;
        foreach (self::PRICES as $name => $p) {
            if (($model === $name || str_starts_with($model, $name . '-20')) && (! $best || strlen($name) > strlen($best))) {
                $best = $name;
            }
        }

        return $best ? self::PRICES[$best] : null;
    }

    /**
     * Is this model available to our API key? The model list is cached for a
     * day. If the list itself can't be fetched we can't judge, so we don't block
     * (the call will fail loudly if the model really is missing).
     */
    public static function available(string $model): bool
    {
        $ids = Cache::remember('openai-models', now()->addDay(), function () {
            $key = (string) Setting::get('openai_key', config('services.openai.key'));
            if (blank($key)) {
                return null;
            }
            try {
                return collect(Http::withToken(trim($key))->timeout(15)->get('https://api.openai.com/v1/models')->throw()->json('data', []))
                    ->pluck('id')->all();
            } catch (\Throwable $e) {
                Log::warning('Could not list OpenAI models: ' . $e->getMessage());

                return null;
            }
        });

        // No usable list (not fetched, or empty) = can't judge here; the call itself fails loudly.
        return ! is_array($ids) || $ids === [] || in_array($model, $ids, true);
    }
}
