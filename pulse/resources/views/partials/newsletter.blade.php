{{-- ═══════════════════════════════════════════════════════════════
     THE DEFENDER MORNING BRIEF — reusable newsletter capture.
     A named editorial product, not a generic "subscribe" box.

     Params:
       $variant : 'full' (default) | 'compact' | 'inline'
       $source  : subscribe-source + analytics cta_location
                  (e.g. homepage_mid, homepage_bottom, article_inline, article_end)
     Reuses the existing /subscribe backend via data-subscribe (audience.js).
     ═══════════════════════════════════════════════════════════════ --}}
@php
  $variant = $variant ?? 'full';
  $source = $source ?? 'newsletter';
@endphp

@if($variant === 'inline')
  {{-- Slim, in-article capture — sits mid-story without interrupting the read. --}}
  <aside class="mb-inline" aria-label="The Defender Morning Brief" data-cta-location="{{ $source }}">
    <div class="mb-inline-head">
      <span class="mb-flag">🇺🇸</span>
      <div>
        <strong>Enjoying The True Defender?</strong>
        <span>Get tomorrow's biggest American stories in one quick morning briefing.</span>
      </div>
    </div>
    <form class="mb-inline-form" data-subscribe data-source="{{ $source }}" data-cta="newsletter" data-cta-location="{{ $source }}">
      <input type="email" name="email" placeholder="your@email.com" required aria-label="Email address" />
      <button type="submit">Join the Morning Brief →</button>
    </form>
    <span class="mb-trust">Free · No spam · Unsubscribe anytime</span>
  </aside>

@elseif($variant === 'compact')
  {{-- Compact in-feed block — drops into the homepage after the first story group. --}}
  <aside class="mb-compact" aria-label="The Defender Morning Brief" data-cta-location="{{ $source }}">
    <div class="mb-compact-text">
      <span class="mb-kicker">🇺🇸 The Defender Morning Brief</span>
      <strong>5 stories Americans need to know.</strong>
      <span class="mb-sub">3 minutes. Every morning. Free.</span>
    </div>
    <form class="mb-compact-form" data-subscribe data-source="{{ $source }}" data-cta="newsletter" data-cta-location="{{ $source }}">
      <input type="email" name="email" placeholder="your@email.com" required aria-label="Email address" />
      <button type="submit">Get Tomorrow's Brief →</button>
      <span class="mb-trust">No spam. Unsubscribe anytime.</span>
    </form>
  </aside>

@else
  {{-- Full hero-style block — homepage bottom / high-emphasis placements. --}}
  <div class="mb-full tilt-card" data-tilt data-cta-location="{{ $source }}">
    <div class="card-glare"></div>
    <div class="mb-full-text">
      <span class="mb-kicker">🇺🇸 The Defender Morning Brief</span>
      <h2>5 stories Americans need to know.</h2>
      <p>3 minutes. Every morning. Free. The day's biggest stories on Politics, Trump, America &amp; Breaking News — delivered before your coffee's cold.</p>
      <span class="mb-topics">Politics • Trump • America • Breaking News</span>
    </div>
    <form class="mb-full-form" data-subscribe data-source="{{ $source }}" data-cta="newsletter" data-cta-location="{{ $source }}">
      <input type="email" name="email" placeholder="your@email.com" required aria-label="Email address" />
      <button type="submit">Get Tomorrow's Brief →</button>
      <span class="mb-trust">Free · No spam · Unsubscribe anytime</span>
    </form>
  </div>
@endif
