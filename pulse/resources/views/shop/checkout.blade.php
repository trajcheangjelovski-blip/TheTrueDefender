@extends('layouts.app')
@section('title', 'Checkout — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:1000px">
    <h1>Checkout</h1>
    <p class="page-sub">Your gift is on us — just enter your shipping details and cover shipping &amp; handling, paid securely by card. Thank you for supporting TheTrueDefender.</p>

    <div class="checkout-grid">
      <form method="POST" action="{{ route('checkout.place') }}" class="contact-form checkout-form" id="checkoutForm">
        @csrf
        <h2 style="margin-top:0">Shipping details</h2>
        <div>
          <label for="cn">Full Name</label>
          <input type="text" id="cn" name="customer_name" value="{{ old('customer_name') }}" required />
          @error('customer_name')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div>
          <label for="ce">Email</label>
          <input type="email" id="ce" name="customer_email" value="{{ old('customer_email') }}" required />
          @error('customer_email')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div>
          <label for="cp">Phone (optional)</label>
          <input type="text" id="cp" name="customer_phone" value="{{ old('customer_phone') }}" />
        </div>
        <div>
          <label for="ca">Shipping Address</label>
          <textarea id="ca" name="shipping_address" rows="3" required>{{ old('shipping_address') }}</textarea>
          @error('shipping_address')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div>
          <label for="cnotes">Order Notes (optional)</label>
          <textarea id="cnotes" name="notes" rows="2">{{ old('notes') }}</textarea>
        </div>

        @if($stripeEnabled)
          <h2 style="margin-top:8px">Card payment</h2>
          <div id="payment-element" style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px"></div>
          <p id="pay-error" style="color:var(--accent-2);font-size:.85rem;margin:8px 0 0;display:none"></p>
          <p class="cart-note">🔒 Your card is processed securely by Stripe. We never see or store your card details.</p>
        @else
          <p class="cart-note">Payment is arranged after you place the order (cash on delivery / bank transfer).</p>
        @endif

        <button type="submit" id="payBtn">{{ $stripeEnabled ? 'Pay $' . number_format($total, 2) . ' & Place Order' : 'Place Order' }}</button>
      </form>

      <aside class="order-summary">
        <h2>Order summary</h2>
        @foreach($lines as $line)
          <div class="os-row">
            <span>
              {{ $line['quantity'] }} × {{ $line['product']->name }}
              @if($line['variant'] && $line['variant']->label)<span style="color:var(--text-dim);font-size:.85em"> ({{ $line['variant']->label }})</span>@endif
              @if($line['unit_price'] == 0.0)<span style="color:#10b981;font-weight:700;font-size:.8em"> FREE</span>@endif
            </span>
            <span>${{ number_format($line['line_total'], 2) }}</span>
          </div>
        @endforeach
        <div class="os-row">
          <span>Shipping &amp; handling</span>
          <span>{{ $shipping > 0 ? '$' . number_format($shipping, 2) : 'Free' }}</span>
        </div>
        <div class="os-row os-total">
          <strong>Total</strong>
          <strong>${{ number_format($total, 2) }}</strong>
        </div>
      </aside>
    </div>
  </main>
@endsection

@if($stripeEnabled)
  @push('scripts')
    <script src="https://js.stripe.com/v3/"></script>
    <script>
      (function () {
        const stripe = Stripe(@json($stripeKey));
        const elements = stripe.elements({ clientSecret: @json($clientSecret) });
        elements.create('payment').mount('#payment-element');

        const form = document.getElementById('checkoutForm');
        const btn = document.getElementById('payBtn');
        const errEl = document.getElementById('pay-error');
        const btnText = btn.textContent;

        function showError(msg) {
          errEl.textContent = msg;
          errEl.style.display = 'block';
          btn.disabled = false;
          btn.textContent = btnText;
        }

        form.addEventListener('submit', async function (e) {
          e.preventDefault();
          errEl.style.display = 'none';
          btn.disabled = true;
          btn.textContent = 'Processing…';

          // 1) Create the order (validates shipping) and attach it to the intent.
          let data;
          try {
            const res = await fetch(form.action, {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
              body: new FormData(form),
            });
            data = await res.json();
            if (!res.ok || !data.ok) {
              const firstErr = data.errors ? Object.values(data.errors)[0][0] : (data.message || 'Please check your details.');
              return showError(firstErr);
            }
          } catch (_) {
            return showError('Could not start the payment. Please try again.');
          }

          // 2) Confirm the card payment; Stripe redirects to the return URL on success.
          const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: { return_url: data.return_url },
          });
          if (error) showError(error.message || 'Payment could not be completed.');
        });
      })();
    </script>
  @endpush
@endif
