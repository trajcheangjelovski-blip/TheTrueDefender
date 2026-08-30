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

    <p class="page-sub" style="margin-top:6px">Welcome to the TheTrueDefender <strong>patriot gifts</strong> shop. Every item here is our free gift to you — you only pay shipping &amp; handling, securely by card through Stripe. And every order helps keep TheTrueDefender's journalism free and unfiltered.</p>

    {{-- SEO/intro copy: gives the shop real indexable text + a heading + the focus
         keyword in the first paragraph, which a bare product grid lacks. --}}
    <div style="max-width:820px;margin:14px 0 4px;line-height:1.7;opacity:.85">
      <h2 style="font-size:1.15rem;margin-bottom:.4rem">How Our Patriot Gifts Work</h2>
      <p>Browse the patriot gifts below and add any item to your cart. At checkout you cover only shipping and handling — the gift itself is free. Your support helps fund independent American journalism, so every order does double duty. New patriot gifts are added regularly, so check back often.</p>
    </div>

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
