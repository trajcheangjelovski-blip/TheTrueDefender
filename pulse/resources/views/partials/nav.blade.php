@php $home = route('home'); @endphp
<header class="nav-wrap" id="navWrap">
  <nav class="nav">
    <a href="{{ $home }}" class="logo">
      <span class="logo-mark">TTD</span>
      <span class="logo-text">The True <em>Defender</em></span>
    </a>
    <ul class="nav-links" id="navLinks">
      <li><a href="{{ $home }}" class="{{ request()->routeIs('home') ? 'active' : '' }}">Home</a></li>
      @foreach($navCategories as $cat)
        <li><a href="{{ route('category.show', $cat) }}"
               class="{{ request()->routeIs('category.show') && request()->route('category')?->id === $cat->id ? 'active' : '' }}">
          {{ $cat->name }}
        </a></li>
      @endforeach
      <li><a href="{{ route('shop.index') }}" class="nav-shop {{ request()->routeIs('shop.*', 'product.*') ? 'active' : '' }}">🎁 Free Gifts</a></li>
    </ul>
    <div class="nav-actions">
      <button class="btn-icon" id="searchBtn" aria-label="Search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      </button>
      <a href="{{ route('cart.show') }}" class="btn-icon cart-btn" aria-label="Cart">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <span class="cart-count {{ ($cartCount ?? 0) > 0 ? '' : 'hidden' }}" id="cartCount">{{ $cartCount ?? 0 }}</span>
      </a>
      <button class="btn-subscribe">Subscribe</button>
      <button class="hamburger" id="hamburger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>
  <div class="search-overlay" id="searchOverlay">
    <input type="text" placeholder="Search stories, topics, authors…" id="searchInput" />
    <button class="search-close" id="searchClose">✕</button>
  </div>
</header>
