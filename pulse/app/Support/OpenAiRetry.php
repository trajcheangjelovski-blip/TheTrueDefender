<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;

class OpenAiRetry
{
    /**
     * Retry policy for OpenAI calls: retry transient failures (network blips,
     * rate limits, 5xx, the occasional bogus 401) but NEVER retry
     * insufficient_quota — an empty account won't refill between attempts,
     * so retrying only burns request time (and can blow PHP's execution limit).
     */
    public static function when(): \Closure
    {
        return function (\Throwable $exception): bool {
            if (! $exception instanceof RequestException) {
                return true; // connection error — worth retrying
            }

            $response = $exception->response;
            $status = $response->status();

            if ($status === 429 && $response->json('error.code') === 'insufficient_quota') {
                return false; // out of credits — fail fast to the local fallback
            }

            return in_array($status, [401, 408, 429], true) || $status >= 500;
        };
    }
}
