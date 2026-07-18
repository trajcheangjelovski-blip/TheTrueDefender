<?php

namespace App\Services;

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
        $subs = PushSubscription::all();

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
