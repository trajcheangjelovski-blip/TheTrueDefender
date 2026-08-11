<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushSender
{
    /**
     * Send a notification payload to every stored push subscription.
     * Expired/invalid endpoints are pruned automatically.
     *
     * @param  array{title:string,body:string,url?:string,icon?:string}  $payload
     */
    public function sendToAll(array $payload): int
    {
        return $this->deliver(PushSubscription::all(), $payload);
    }

    /**
     * Topic-aware delivery for a specific post. Recipients:
     *   • every GLOBAL subscriber (topics_only = false) — the default, so
     *     behaviour is unchanged for everyone who never chose topics; PLUS
     *   • any topic-scoped subscriber that follows one of the post's topics.
     * Breaking news overrides topic scoping (urgent → everyone). Falls back to
     * a full send when the post carries no topics, so nothing is ever dropped.
     */
    public function sendToPost(Post $post, array $payload): int
    {
        $tagIds = $post->relationLoaded('tags') ? $post->tags->pluck('id') : $post->tags()->pluck('tags.id');

        // No topics on the post, or it's breaking → send to everyone (as today).
        if ($tagIds->isEmpty() || $post->is_breaking_now) {
            return $this->sendToAll($payload);
        }

        $subs = PushSubscription::where('topics_only', false)
            ->orWhereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
            ->get();

        return $this->deliver($subs, $payload);
    }

    /**
     * Low-level batch send to a given set of subscriptions. Prunes expired ones.
     *
     * @param  \Illuminate\Support\Collection<int,PushSubscription>  $subs
     */
    private function deliver($subs, array $payload): int
    {
        $auth = [
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ];

        if (blank($auth['VAPID']['publicKey']) || blank($auth['VAPID']['privateKey'])) {
            Log::warning('Web push skipped: VAPID keys not configured.');
            return 0;
        }

        $webPush = new WebPush($auth);

        foreach ($subs as $sub) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                ]);

                $webPush->queueNotification($subscription, json_encode($payload));
            } catch (\Throwable $e) {
                // A single malformed subscription must not break the whole batch.
                Log::warning("Push queue skipped for #{$sub->id}: " . $e->getMessage());
            }
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            } else {
                Log::warning('Push failed: ' . $report->getReason());
            }
        }

        return $sent;
    }
}
