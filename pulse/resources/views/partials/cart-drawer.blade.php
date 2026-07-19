{{-- Mini-cart drawer: slides in from the right after "Add to Cart".
     Line items + totals are rendered by JS from the cart.add JSON response. --}}
<div class="cart-drawer-overlay" id="cartDrawerOverlay" aria-hidden="true"></div>
<aside class="cart-drawer" id="cartDrawer" role="dialog" aria-label="Shopping cart" aria-hidden="true">
  <div class="cart-drawer-head">
    <h3>Your Cart <span id="cartDrawerCount"></span></h3>
    <button type="button" class="cart-drawer-close" data-drawer-close aria-label="Close">✕</button>
  </div>
  <p class="cart-drawer-added" id="cartDrawerAdded"></p>
  <div class="cart-drawer-lines" id="cartDrawerLines"></div>
  <div class="cart-drawer-foot">
    <div class="cart-summary-row"><span>Subtotal</span><strong id="cartDrawerSubtotal"></strong></div>
    <div class="cart-summary-row"><span>Shipping &amp; handling</span><strong id="cartDrawerShipping"></strong></div>
    <div class="cart-summary-row cart-drawer-total"><span>Total</span><strong id="cartDrawerTotal"></strong></div>
    <a href="{{ route('checkout') }}" class="btn-checkout">Proceed to Checkout →</a>
    <div class="cart-drawer-links">
      <button type="button" class="cart-drawer-continue" data-drawer-close>← Continue shopping</button>
      <a href="{{ route('cart.show') }}">View full cart</a>
    </div>
  </div>
</aside>
