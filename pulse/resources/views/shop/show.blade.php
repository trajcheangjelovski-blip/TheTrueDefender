@extends('layouts.app')
@section('title', $product->name . ' — Patriot Shop')

@section('content')
  @php
    $hasVariants = $product->hasVariants();
    $axes = $product->optionAxes(); // ['Color'=>[...], 'Size'=>[...], 'Style'=>[...]]
    // Variant data for the client-side selector.
    $variantData = $product->activeVariants()->map(fn ($v) => [
        'id' => $v->id,
        'color' => $v->color,
        'size' => $v->size,
        'style' => $v->style,
        'price' => $v->current_price,
        'regular' => $v->regular_price,
        'on_sale' => $v->on_sale,
        'stock' => $v->stock,
        'image' => $v->image ? asset('storage/' . $v->image) : null,
    ])->values();
  @endphp

  <main class="page-main" style="max-width:1100px">
    <a href="{{ route('shop.index') }}" class="back-link" style="display:inline-block;margin-bottom:22px">← Back to shop</a>

    @if(session('status'))
      <p class="flash-ok">{{ session('status') }}</p>
    @endif

    <div class="product-detail">
      <div class="pd-image">
        @if($product->image)
          <img id="pdMainImage" src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}" />
        @else
          <span class="prod-icon" style="font-size:6rem">{{ $product->image_icon ?? '🛍️' }}</span>
        @endif
        @if($product->tag)<span class="prod-tag">{{ $product->tag }}</span>@endif
      </div>

      <div class="pd-info">
        <h1>{{ $product->name }}</h1>

        @if($product->short_description)
          <p class="pd-short">{{ $product->short_description }}</p>
        @endif

        <div class="pd-price" id="pdPrice"
             data-base="{{ number_format($product->current_price, 2, '.', '') }}"
             data-ship="{{ number_format($product->shipping_price, 2, '.', '') }}">
          @if($product->has_price_range)
            <span style="font-size:.5em;color:var(--text-dim);font-weight:600">from</span>
            ${{ number_format($product->price_from, 2) }}
          @elseif($product->is_free)
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

        @if($product->description)
          <p class="pd-desc">{{ $product->description }}</p>
        @endif

        <form method="POST" action="{{ route('cart.add') }}" class="pd-buy"
              @if($hasVariants) data-variants='@json($variantData)' data-axes='@json($axes)' @endif>
          @csrf
          <input type="hidden" name="product_id" value="{{ $product->id }}" />
          <input type="hidden" name="variant_id" id="pdVariantId" value="" />

          @if($hasVariants)
            <div class="pd-options">
              @foreach($axes as $label => $values)
                <div class="pd-option" data-axis="{{ $label }}">
                  <label>{{ $label }}</label>
                  <div class="pd-swatches">
                    @foreach($values as $val)
                      <button type="button" class="pd-swatch" data-axis="{{ $label }}" data-value="{{ $val }}">{{ $val }}</button>
                    @endforeach
                  </div>
                </div>
              @endforeach
            </div>
            <p class="pd-variant-note" id="pdVariantNote">Choose your options above.</p>
          @endif

          <div class="pd-buy-row">
            <input type="number" name="quantity" value="1" min="1" max="99" class="qty-input" />
            <button type="submit" class="btn-cart pd-add" @if($hasVariants) disabled @endif>Add to Cart</button>
          </div>
        </form>

        @if($product->sku)<p class="pd-sku">SKU: {{ $product->sku }}</p>@endif

        @if($product->details)
          <div class="pd-details">
            <h3>Product Details</h3>
            @php $lines = preg_split('/\r\n|\r|\n/', trim($product->details)); $lines = array_filter(array_map('trim', $lines)); @endphp
            @if(count($lines) > 1)
              <ul>
                @foreach($lines as $line)<li>{{ $line }}</li>@endforeach
              </ul>
            @else
              <p>{{ $product->details }}</p>
            @endif
          </div>
        @endif
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
