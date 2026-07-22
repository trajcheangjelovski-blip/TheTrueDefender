<article class="product-card tilt-card" data-tilt>
  <div class="card-glare"></div>
  @if($product->tag)<span class="prod-tag">{{ $product->tag }}</span>@endif
  <a href="{{ route('product.show', $product) }}" class="prod-img">
    @if($product->image)
      <img src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->name }}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain;padding:18px" />
    @else
      <span class="prod-icon">{{ $product->image_icon ?? '🛍️' }}</span>
    @endif
  </a>
  <div class="prod-body">
    <h3><a href="{{ route('product.show', $product) }}" style="color:inherit">{{ $product->name }}</a></h3>
    <div class="prod-foot">
      <span class="prod-price">
        @if($product->has_price_range)
          <span style="font-size:.6em;color:var(--text-dim);font-weight:500">from</span>
          ${{ number_format($product->price_from + $product->shipping_price, 2) }}
        @else
          ${{ number_format($product->delivered_price, 2) }}
        @endif
        <span style="display:block;font-size:.6em;color:var(--text-dim);font-weight:500">delivered</span>
      </span>
      <button class="btn-cart" type="button" data-product-id="{{ $product->id }}">Add to Cart</button>
    </div>
  </div>
</article>
