@extends('layouts.app')
@section('title', 'Your Cart — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:960px">
    <h1>Your Cart</h1>

    @if(session('status'))
      <p class="flash-ok">{{ session('status') }}</p>
    @endif

    @if($lines->isEmpty())
      <p class="page-sub">Your cart is empty.</p>
      <a href="{{ route('shop.index') }}" class="btn-cart" style="display:inline-block;text-decoration:none">Browse the shop</a>
    @else
      <div class="cart-list">
        @foreach($lines as $line)
          @php $p = $line['product']; $v = $line['variant']; $thumb = $v?->image ?: $p->image; @endphp
          <div class="cart-row">
            <div class="cart-thumb">
              @if($thumb)
                <img src="{{ asset('storage/' . $thumb) }}" alt="{{ $p->name }}" />
              @else
                <span>{{ $p->image_icon ?? '🛍️' }}</span>
              @endif
            </div>
            <div class="cart-info">
              <a href="{{ route('product.show', $p) }}"><h3>{{ $p->name }}</h3></a>
              @if($v && $v->label)<span class="cart-variant">{{ $v->label }}</span>@endif
              <span class="cart-unit">${{ number_format($line['unit_price'], 2) }} each</span>
            </div>
            <form method="POST" action="{{ route('cart.update') }}" class="cart-qty-form">
              @csrf
              <input type="hidden" name="key" value="{{ $line['key'] }}" />
              <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="0" max="99" class="qty-input" onchange="this.form.submit()" />
            </form>
            <div class="cart-line-total">${{ number_format($line['line_total'], 2) }}</div>
            <form method="POST" action="{{ route('cart.remove') }}">
              @csrf
              <input type="hidden" name="key" value="{{ $line['key'] }}" />
              <button type="submit" class="cart-remove" aria-label="Remove">✕</button>
            </form>
          </div>
        @endforeach
      </div>

      <div class="cart-summary">
        <div class="cart-summary-row">
          <span>Subtotal</span>
          <strong>${{ number_format($subtotal, 2) }}</strong>
        </div>
        <div class="cart-summary-row">
          <span>Shipping</span>
          <strong>{{ $shipping > 0 ? '$' . number_format($shipping, 2) : 'Free' }}</strong>
        </div>
        <div class="cart-summary-row" style="border-top:1px solid rgba(255,255,255,.12);padding-top:10px;margin-top:6px">
          <span>Total</span>
          <strong>${{ number_format($total, 2) }}</strong>
        </div>
        <a href="{{ route('checkout') }}" class="btn-checkout">Proceed to Checkout →</a>
      </div>
    @endif
  </main>
@endsection
