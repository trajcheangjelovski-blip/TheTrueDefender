<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe payments via Stripe Elements (Payment Element) — the card fields are
 * embedded on our own checkout page, but the card data is captured by Stripe.js
 * and sent straight to Stripe, never touching this server (SAQ-A PCI).
 *
 * Configure in Admin → AI & Ads Settings → Payments (Stripe). Needs the
 * publishable + secret keys. When unconfigured, checkout gracefully falls back
 * to the cash-on-delivery flow.
 */
class StripeService
{
    /** Embedded card checkout needs BOTH the publishable and secret keys. */
    public function isConfigured(): bool
    {
        return filled($this->secret()) && filled($this->publishableKey());
    }

    public function publishableKey(): ?string
    {
        $key = Setting::get('stripe_key', config('services.stripe.key'));

        return filled($key) ? trim($key) : null;
    }

    private function secret(): ?string
    {
        $key = Setting::get('stripe_secret', config('services.stripe.secret'));

        return filled($key) ? trim($key) : null;
    }

    /**
     * Create a PaymentIntent for an amount (USD). Returns ['id', 'client_secret'].
     */
    public function createPaymentIntent(float $amount, array $metadata = []): ?array
    {
        if (! $this->isConfigured() || $amount <= 0) {
            return null;
        }

        try {
            $params = [
                'amount' => (int) round($amount * 100),
                'currency' => 'usd',
                'automatic_payment_methods[enabled]' => 'true',
            ];
            foreach ($metadata as $k => $v) {
                $params["metadata[{$k}]"] = $v;
            }

            $intent = Http::withToken($this->secret())->asForm()->timeout(30)
                ->post('https://api.stripe.com/v1/payment_intents', $params)
                ->throw()->json();

            return ['id' => $intent['id'] ?? null, 'client_secret' => $intent['client_secret'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('Stripe PaymentIntent create failed: ' . $e->getMessage());

            return null;
        }
    }

    /** Update an existing PaymentIntent's amount + metadata (order confirmed). */
    public function updatePaymentIntent(string $id, float $amount, array $metadata = []): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $params = ['amount' => (int) round($amount * 100)];
            foreach ($metadata as $k => $v) {
                $params["metadata[{$k}]"] = $v;
            }
            Http::withToken($this->secret())->asForm()->timeout(30)
                ->post('https://api.stripe.com/v1/payment_intents/' . urlencode($id), $params)
                ->throw();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Stripe PaymentIntent update failed: ' . $e->getMessage());

            return false;
        }
    }

    /** True once a PaymentIntent has succeeded. */
    public function paymentIntentSucceeded(string $id): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $intent = Http::withToken($this->secret())->timeout(30)
                ->get('https://api.stripe.com/v1/payment_intents/' . urlencode($id))
                ->throw()->json();

            return ($intent['status'] ?? null) === 'succeeded';
        } catch (\Throwable $e) {
            Log::warning('Stripe PaymentIntent check failed: ' . $e->getMessage());

            return false;
        }
    }

    private function webhookSecret(): ?string
    {
        $key = Setting::get('stripe_webhook_secret', config('services.stripe.webhook_secret'));

        return filled($key) ? trim($key) : null;
    }

    /**
     * Create a hosted Checkout Session for an order and return its payment URL.
     * Free-plus-shipping items appear as $0 lines with shipping as its own line.
     *
     * @param Collection<int,array{product:\App\Models\Product,quantity:int,line_total:float}> $lines
     */
    public function checkoutUrl(Order $order, Collection $lines): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $params = [
            'mode' => 'payment',
            'success_url' => route('checkout.success', $order) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout') . '?cancelled=1',
            'customer_email' => $order->customer_email,
            'client_reference_id' => $order->order_number,
            'metadata[order_number]' => $order->order_number,
        ];

        $i = 0;
        foreach ($lines as $line) {
            $product = $line['product'];
            $params["line_items[{$i}][quantity]"] = $line['quantity'];
            $params["line_items[{$i}][price_data][currency]"] = 'usd';
            $params["line_items[{$i}][price_data][unit_amount]"] = (int) round($product->current_price * 100);
            $params["line_items[{$i}][price_data][product_data][name]"] =
                $product->name . ($product->is_free ? ' (FREE — just pay shipping)' : '');
            $i++;
        }

        // Shipping as a single dedicated line so the math matches the order.
        if ((float) $order->shipping > 0) {
            $params["line_items[{$i}][quantity]"] = 1;
            $params["line_items[{$i}][price_data][currency]"] = 'usd';
            $params["line_items[{$i}][price_data][unit_amount]"] = (int) round($order->shipping * 100);
            $params["line_items[{$i}][price_data][product_data][name]"] = 'Shipping & handling';
        }

        try {
            $session = Http::withToken($this->secret())
                ->asForm()
                ->timeout(30)
                ->post('https://api.stripe.com/v1/checkout/sessions', $params)
                ->throw()
                ->json();

            $order->forceFill([
                'payment_method' => 'stripe',
                'stripe_session_id' => $session['id'] ?? null,
            ])->save();

            return $session['url'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("Stripe session failed for {$order->order_number}: " . $e->getMessage());

            return null;
        }
    }

    /** True when the Checkout Session has been paid (success-page check). */
    public function sessionPaid(string $sessionId): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $session = Http::withToken($this->secret())
                ->timeout(30)
                ->get('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId))
                ->throw()
                ->json();

            return ($session['payment_status'] ?? null) === 'paid';
        } catch (\Throwable $e) {
            Log::warning('Stripe session check failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Verify a webhook's Stripe-Signature header (HMAC-SHA256) and return the
     * decoded event, or null if invalid. 5-minute replay tolerance.
     */
    public function verifyWebhook(string $payload, ?string $signatureHeader): ?array
    {
        $secret = $this->webhookSecret();
        if (blank($secret) || blank($signatureHeader)) {
            return null;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if (! $timestamp || empty($signatures) || abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, (string) $signature)) {
                return json_decode($payload, true);
            }
        }

        return null;
    }

    /** Mark an order paid (idempotent) — used by both success page and webhook. */
    public function markPaid(Order $order): void
    {
        if ($order->paid_at !== null) {
            return;
        }

        $order->forceFill([
            'status' => $order->status === 'pending' ? 'paid' : $order->status,
            'paid_at' => now(),
        ])->save();
    }
}
