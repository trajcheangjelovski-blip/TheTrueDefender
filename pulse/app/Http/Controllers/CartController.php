<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\AffiliateProgram;
use App\Services\Cart;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function show(Cart $cart)
    {
        return view('shop.cart', [
            'lines' => $cart->lines(),
            'subtotal' => $cart->subtotal(),
            'shipping' => $cart->shipping(),
            'total' => $cart->total(),
        ]);
    }

    public function add(Request $request, Cart $cart)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::active()->findOrFail($data['product_id']);

        // Products with options require a valid variant selection.
        $variantId = null;
        if ($product->hasVariants()) {
            $variant = $product->activeVariants()->firstWhere('id', $data['variant_id'] ?? null);
            if (! $variant) {
                return $request->expectsJson()
                    ? response()->json(['ok' => false, 'message' => 'Please choose an option first.'], 422)
                    : back()->with('status', 'Please choose an option first.');
            }
            $variantId = $variant->id;
        }

        $cart->add($product->id, $data['quantity'] ?? 1, $variantId);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'count' => $cart->count(),
                'message' => $product->name . ' added to cart',
                'added' => $product->name,
                'cart' => $this->drawerPayload($cart),
            ]);
        }

        return back()->with('status', $product->name . ' added to cart.');
    }

    /** Cart snapshot for the mini-cart drawer (rendered client-side after add-to-cart). */
    private function drawerPayload(Cart $cart): array
    {
        return [
            'count' => $cart->count(),
            'subtotal' => number_format($cart->subtotal(), 2),
            'shipping' => $cart->shipping() > 0 ? number_format($cart->shipping(), 2) : null,
            'total' => number_format($cart->total(), 2),
            'lines' => $cart->lines()->map(function (array $line) {
                $img = $line['variant']?->image ?: $line['product']->image;

                return [
                    'name' => $line['product']->name,
                    'variant' => $line['variant']?->label ?: null,
                    'url' => route('product.show', $line['product']),
                    'image' => $img ? asset('storage/' . $img) : null,
                    'icon' => $line['product']->image_icon ?? '🛍️',
                    'quantity' => $line['quantity'],
                    'is_free' => $line['unit_price'] == 0.0,
                    'line_total' => number_format($line['line_total'], 2),
                ];
            })->all(),
        ];
    }

    public function update(Request $request, Cart $cart)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $cart->update($data['key'], $data['quantity']);

        return back()->with('status', 'Cart updated.');
    }

    public function remove(Request $request, Cart $cart)
    {
        $cart->remove((string) $request->input('key'));

        return back()->with('status', 'Item removed.');
    }

    public function checkout(Cart $cart, StripeService $stripe)
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.show')->with('status', 'Your cart is empty.');
        }

        // Prepare an on-page card form: create a PaymentIntent for the total.
        $clientSecret = null;
        if ($stripe->isConfigured() && $cart->total() > 0) {
            $intent = $stripe->createPaymentIntent($cart->total());
            if ($intent) {
                session(['stripe_pi' => $intent['id']]);
                $clientSecret = $intent['client_secret'];
            }
        }

        return view('shop.checkout', [
            'lines' => $cart->lines(),
            'subtotal' => $cart->subtotal(),
            'shipping' => $cart->shipping(),
            'total' => $cart->total(),
            'stripeEnabled' => $clientSecret !== null,
            'stripeKey' => $stripe->publishableKey(),
            'clientSecret' => $clientSecret,
        ]);
    }

    public function place(Request $request, Cart $cart)
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.show')->with('status', 'Your cart is empty.');
        }

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'shipping_address' => ['required', 'string', 'max:600'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $lines = $cart->lines();
        $subtotal = $cart->subtotal();
        $shipping = $cart->shipping();

        $order = DB::transaction(function () use ($data, $lines, $subtotal, $shipping) {
            $order = Order::create([
                ...$data,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'total' => round($subtotal + $shipping, 2),
                'payment_method' => 'cod',
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $variant = $line['variant'];
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'name' => $product->name,
                    'variant_label' => $variant?->label ?: null,
                    'price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                if ($product->track_stock) {
                    $variant
                        ? $variant->decrement('stock', $line['quantity'])
                        : $product->decrement('stock', $line['quantity']);
                }
            }

            return $order;
        });

        // Credit the referring affiliate (attribution cookie set by TrackAffiliate).
        app(AffiliateProgram::class)->recordConversion($order, $request->cookie(AffiliateProgram::COOKIE));

        // Stripe (embedded card) flow: attach the order to the PaymentIntent and
        // hand the client the return URL — the card is confirmed by Stripe.js.
        $stripe = app(StripeService::class);
        $piId = session('stripe_pi');
        if ($order->total > 0 && $stripe->isConfigured() && $piId) {
            $stripe->updatePaymentIntent($piId, $order->total, ['order_number' => $order->order_number]);
            $order->forceFill(['payment_method' => 'stripe', 'stripe_session_id' => $piId])->save();

            return response()->json([
                'ok' => true,
                'return_url' => route('checkout.success', $order),
            ]);
        }

        // Fallback: cash-on-delivery style pending order (no Stripe configured).
        $cart->clear();

        return redirect()->route('order.confirmation', $order)
            ->with('status', 'Order placed successfully!');
    }

    /** Stripe return — verify the PaymentIntent, mark paid, show confirmation. */
    public function success(Request $request, Order $order, Cart $cart, StripeService $stripe)
    {
        if ($order->paid_at === null
            && filled($order->stripe_session_id)
            && $stripe->paymentIntentSucceeded($order->stripe_session_id)) {
            $stripe->markPaid($order);
        }

        if ($order->paid_at !== null) {
            $cart->clear();
            session()->forget('stripe_pi');

            return redirect()->route('order.confirmation', $order)
                ->with('status', 'Payment received — thank you!');
        }

        return redirect()->route('checkout')
            ->with('status', 'Payment not completed yet. Please try again.');
    }

    public function confirmation(Order $order)
    {
        $order->load('items');

        return view('shop.confirmation', compact('order'));
    }
}
