@php $home = route('home'); @endphp
<footer class="footer">
  <div class="footer-grid">
    <div class="footer-brand">
      <a href="{{ $home }}" class="logo">
        <span class="logo-mark">TTD</span>
        <span class="logo-text">The True <em>Defender</em></span>
      </a>
      <p>Independent journalism. Unfiltered news. Delivered with integrity since 2026.</p>
      @php
        $socials = [
          'social_x' => ['label' => 'X', 'glyph' => '𝕏'],
          'social_facebook' => ['label' => 'Facebook', 'glyph' => 'f'],
          'social_youtube' => ['label' => 'YouTube', 'glyph' => '▶'],
          'social_truth' => ['label' => 'Truth Social', 'glyph' => 'T'],
          'social_telegram' => ['label' => 'Telegram', 'glyph' => '<svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>'],
        ];
      @endphp
      <div class="socials">
        @foreach($socials as $key => $s)
          @php $url = \App\Models\Setting::get($key); @endphp
          @if(filled($url))
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $s['label'] }}">{!! $s['glyph'] !!}</a>
          @endif
        @endforeach
      </div>
    </div>
    <div class="footer-col">
      <h4>Categories</h4>
      @foreach($navCategories as $cat)
        <a href="{{ route('category.show', $cat) }}">{{ $cat->name }}</a>
      @endforeach
    </div>
    <div class="footer-col">
      <h4>More</h4>
      <a href="{{ route('shop.index') }}">Patriot Shop</a>
      <a href="{{ route('page', 'about') }}">About Us</a>
      <a href="{{ route('page', 'contact') }}">Contact</a>
      <a href="{{ route('affiliate.apply') }}">Become an Affiliate</a>
    </div>
    <div class="footer-col">
      <h4>Editorial</h4>
      <a href="{{ route('page', 'editorial-standards') }}">Editorial Standards</a>
      <a href="{{ route('page', 'corrections') }}">Corrections</a>
      <a href="{{ route('page', 'privacy') }}">Privacy Policy</a>
      <a href="{{ route('page', 'terms') }}">Terms of Service</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© {{ date('Y') }} TheTrueDefender. All rights reserved.</span>
    <span>Built for truth. Powered by readers.</span>
  </div>
</footer>
