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
        @if($product->is_free)
          <span style="color:#10b981;font-weight:800">FREE</span>
          @if($product->shipping_price > 0)
            <span style="display:block;font-size:.65em;color:var(--text-dim);font-weight:500">+ ${{ number_format($product->shipping_price, 2) }} shipping</span>
          @endif
        @else
          @if($product->on_sale)
            <span style="text-decoration:line-through;color:var(--text-dim);font-size:.8em">${{ number_format($product->price, 2) }}</span>
          @endif
          ${{ number_format($product->current_price, 2) }}
        @endif
      </span>
      <button class="btn-cart" type="button" data-product-id="{{ $product->id }}">Add to Cart</button>
    </div>
  </div>
</article>
