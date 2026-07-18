@extends('layouts.app')
@section('title', 'Order Confirmed — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:760px">
    <div class="confirm-badge">✓</div>
    <h1>Thank you for your order!</h1>
    @if($order->paid_at)
      <p class="page-sub">Your order <strong>{{ $order->order_number }}</strong> is <strong style="color:#10b981">paid</strong> ✓ — we'll email <strong>{{ $order->customer_email }}</strong> when it ships.</p>
    @else
      <p class="page-sub">Your order <strong>{{ $order->order_number }}</strong> has been received. We'll email <strong>{{ $order->customer_email }}</strong> with payment and shipping details shortly.</p>
    @endif

    <div class="order-summary" style="margin-top:24px">
      <h2>Order summary</h2>
      @foreach($order->items as $item)
        <div class="os-row">
          <span>{{ $item->quantity }} × {{ $item->name }}</span>
          <span>${{ number_format($item->line_total, 2) }}</span>
        </div>
      @endforeach
      <div class="os-row os-total">
        <strong>Total</strong>
        <strong>${{ number_format($order->total, 2) }}</strong>
      </div>
    </div>

    <a href="{{ route('home') }}" class="back-link" style="display:inline-block;margin-top:26px">← Back to home</a>
  </main>
@endsection
