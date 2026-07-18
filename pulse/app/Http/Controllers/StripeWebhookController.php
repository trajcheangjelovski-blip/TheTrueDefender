<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    /**
     * Stripe → us: payment events. Signature-verified; marks orders paid.
     * This is the reliable path — it works even if the customer closes the
     * browser before returning to the success page.
     */
    public function handle(Request $request, StripeService $stripe)
    {
        $event = $stripe->verifyWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature'),
        );

        if ($event === null) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        if (($event['type'] ?? '') === 'payment_intent.succeeded') {
            $intent = $event['data']['object'] ?? [];

            $order = Order::where('stripe_session_id', $intent['id'] ?? '')->first()
                ?? Order::where('order_number', $intent['metadata']['order_number'] ?? '')->first();

            if ($order) {
                $stripe->markPaid($order);
                Log::info("Stripe webhook: order {$order->order_number} marked paid.");
            }
        }

        return response()->json(['received' => true]);
    }
}
