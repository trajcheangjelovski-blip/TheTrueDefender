<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Compatibility adapter for existing text call sites; durable jobs live on Hetzner. */
final class AiRequest
{
    private int $seconds = 150;

    private int $attempts = 2;

    public function timeout(int $seconds): self
    {
        $this->seconds = $seconds;

        return $this;
    }

    public function retry(int $times, mixed ...$options): self
    {
        $this->attempts = $times;

        return $this;
    }

    public function acceptJson(): self
    {
        return $this;
    }

    public function post(string $url, array $payload): Response
    {
        if (! AiTransport::local()) {
            return Http::withToken(AiTransport::key())->timeout($this->seconds)->withoutRedirecting()
                ->retry($this->attempts, 1500, OpenAiRetry::when(), throw: false)->acceptJson()->post($url, $payload);
        }
        if (! str_ends_with($url, '/chat/completions')) {
            throw new \RuntimeException('Local text provider does not support this operation');
        }
        if (strlen(AiTransport::key()) < 32) {
            throw new \RuntimeException('Configure LOCAL_AI_TOKEN; no paid fallback is allowed');
        }
        $payload['model'] = AiTransport::model();
        unset($payload['reasoning_effort']);
        // Exact input, including the persisted evidence snapshot, is safely reusable.
        $key = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if (config('services.ai.capture_benchmarks') && app()->environment(['local', 'staging'])) {
            Storage::disk('local')->put('benchmark-fixtures/'.$key.'.json', json_encode([
                'name' => $payload['response_format']['json_schema']['name'] ?? 'utility', 'mode' => 'chat', 'payload' => $payload,
            ], JSON_THROW_ON_ERROR));
        }
        $request = fn () => Http::withToken(AiTransport::key())->connectTimeout(5)->timeout(15)->withoutRedirecting()->acceptJson();
        $response = $request()->withHeaders(['Idempotency-Key' => $key])->post(AiTransport::gateway().'/v1/jobs', [
            'mode' => 'chat', 'lane' => AiContext::$lane, 'payload' => $payload,
        ])->throw();
        $id = $response->json('id');
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new \RuntimeException('Invalid gateway job identifier');
        }
        $wait = max(1, (int) config('services.ai.wait_seconds', 900));
        set_time_limit($wait + 60);
        $deadline = microtime(true) + $wait;
        do {
            $job = $request()->get(AiTransport::gateway().'/v1/jobs/'.$id)->throw()->json();
            if (($job['state'] ?? '') === 'done') {
                return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($job['result'], JSON_THROW_ON_ERROR)));
            }
            if (($job['state'] ?? '') === 'failed') {
                throw new \RuntimeException('Local AI job failed; review gateway job '.$id);
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Local AI still queued; retry the unchanged request: '.$id);
    }
}
