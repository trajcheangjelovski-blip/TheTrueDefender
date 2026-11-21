<?php

namespace App\Console\Commands;

use App\Models\NewsroomOutbox as Outbox;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class NewsroomOutbox extends Command
{
    protected $signature = 'newsroom:outbox';

    protected $description = 'Deliver durable, profile-specific article tasks to the coordinator review queue';

    public function handle(): int
    {
        $url = rtrim((string) config('services.truth_coordinator.url'), '/');
        $token = (string) config('services.truth_coordinator.token');
        if ($url === '' || strlen($token) < 32) {
            return self::SUCCESS;
        }
        foreach (Outbox::whereIn('status', ['pending', 'delivery_failed'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(20)->get() as $row) {
            $post = Post::find($row->post_id);
            if (! $post || $post->status !== 'published') {
                $row->update(['status' => 'cancelled', 'error' => 'Article no longer published']);

                continue;
            }
            try {
                Http::withToken($token)->timeout(15)->withoutRedirecting()
                    ->withHeaders(['Idempotency-Key' => $row->idempotency_key])
                    ->post($url.'/api/v1/newsroom/articles', $row->payload)->throw();
                $row->update(['status' => 'delivered', 'error' => null, 'attempts' => $row->attempts + 1]);
            } catch (\Throwable $e) {
                $attempts = $row->attempts + 1;
                $row->update(['status' => 'delivery_failed', 'attempts' => $attempts, 'error' => 'Delivery failed: '.class_basename($e),
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * 2 ** min($attempts, 7)))]);
            }
        }
        foreach (Outbox::whereIn('status', ['delivered', 'pending_review', 'approved'])->limit(100)->get() as $row) {
            try {
                $state = Http::withToken($token)->timeout(10)->withoutRedirecting()->get($url.'/api/v1/newsroom/tasks/'.$row->idempotency_key)->throw()->json();
                if (in_array($state['status'] ?? '', ['pending_review', 'approved', 'published', 'failed', 'rejected'], true)) {
                    $row->update(['status' => $state['status'], 'error' => $state['error'] ?? null, 'external_url' => $state['external_url'] ?? null]);
                }
            } catch (\Throwable) { /* Retry status reconciliation next run. */
            }
        }

        return self::SUCCESS;
    }
}
