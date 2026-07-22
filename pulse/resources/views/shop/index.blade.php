@extends('layouts.app')
@section('title', 'Free Patriot Gifts — TheTrueDefender')
@section('meta_description', 'Claim a free patriot gift — you just cover shipping. Secure card checkout via Stripe. Every order supports independent journalism.')

@section('content')
  <main class="page-main" style="max-width:1360px">
    <div class="section-head" style="margin-top:10px">
      <h2>
        <span class="head-icon" style="background:#c7962a1f; border-color:#c7962a55">🎁</span>
        Free Patriot Gifts
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, #c7962a66, transparent)"></div>
      <span class="head-link" style="color:#e0b04b">Yours free — you just cover shipping</span>
    </div>

    <p class="page-sub" style="margin-top:6px">Every item here is our <strong>free gift</strong> to you — you only pay shipping &amp; handling, securely by card through Stripe. And every order helps keep TheTrueDefender's journalism free and unfiltered.</p>

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
