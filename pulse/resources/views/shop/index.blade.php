@extends('layouts.app')
@section('title', 'Patriot Shop — TheTrueDefender')
@section('meta_description', 'Patriot-themed gear with shipping & handling included in the price. Secure card checkout via Stripe. Every order supports independent journalism.')

@section('content')
  <main class="page-main" style="max-width:1360px">
    <div class="section-head" style="margin-top:10px">
      <h2>
        <span class="head-icon" style="background:#c7962a1f; border-color:#c7962a55">🛍️</span>
        Patriot Shop
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, #c7962a66, transparent)"></div>
      <span class="head-link" style="color:#e0b04b">Shipping &amp; handling included</span>
    </div>

    <p class="page-sub" style="margin-top:6px">Patriot-themed gear — the price you see includes shipping &amp; handling, paid securely by card through Stripe. Every order helps keep TheTrueDefender's journalism independent.</p>

    @if(session('status'))
      <p class="flash-ok">{{ session('status') }}</p>
    @endif

    <div class="shop-grid" id="shopGrid" style="margin-top:20px">
      @forelse($products as $product)
        @include('partials.product-card', ['product' => $product])
      @empty
        <p style="color:var(--text-dim)">No products available yet.</p>
      @endforelse
    </div>
  </main>
@endsection
