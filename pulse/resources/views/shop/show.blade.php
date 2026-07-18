@extends('layouts.app')
@section('title', $product->name . ' — Patriot Shop')

@section('content')
  <main class="page-main" style="max-width:1100px">
    <a href="{{ route('shop.index') }}" class="back-link" style="display:inline-block;margin-bottom:22px">← Back to shop</a>

    @if(session('status'))
      <p class="flash-ok">{{ session('status') }}</p>
    @endif

    <div class="product-detail">
      <div class="pd-image">
        @if($product->image)
          <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}" />
        @else
          <span class="prod-icon" style="font-size:6rem">{{ $product->image_icon ?? '🛍️' }}</span>
        @endif
        @if($product->tag)<span class="prod-tag">{{ $product->tag }}</span>@endif
      </div>

      <div class="pd-info">
        <h1>{{ $product->name }}</h1>
        <div class="pd-price">
          @if($product->is_free)
            <span style="color:#10b981">FREE</span>
            <span style="display:block;font-size:.45em;color:var(--text-dim);font-weight:600;margin-top:4px">
              Just pay ${{ number_format($product->shipping_price, 2) }} shipping &amp; handling
            </span>
          @else
            @if($product->on_sale)
              <span class="old">${{ number_format($product->price, 2) }}</span>
            @endif
            ${{ number_format($product->current_price, 2) }}
            @if($product->shipping_price > 0)
              <span style="display:block;font-size:.45em;color:var(--text-dim);font-weight:600;margin-top:4px">
                + ${{ number_format($product->shipping_price, 2) }} shipping
              </span>
            @endif
          @endif
        </div>

        <p class="pd-desc">{{ $product->description }}</p>

        <form method="POST" action="{{ route('cart.add') }}" class="pd-buy">
          @csrf
          <input type="hidden" name="product_id" value="{{ $product->id }}" />
          <input type="number" name="quantity" value="1" min="1" max="99" class="qty-input" />
          <button type="submit" class="btn-cart pd-add">Add to Cart</button>
        </form>

        @if($product->sku)<p class="pd-sku">SKU: {{ $product->sku }}</p>@endif
      </div>
    </div>

    @if($related->isNotEmpty())
      <div class="section-head" style="margin-top:50px">
        <h2><span class="head-accent">🛍️</span> You may also like</h2>
        <div class="head-line"></div>
      </div>
      <div class="shop-grid">
        @foreach($related as $product)
          @include('partials.product-card', ['product' => $product])
        @endforeach
      </div>
    @endif
  </main>
@endsection
