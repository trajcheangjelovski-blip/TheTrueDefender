<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />

  @php
    $gaId = \App\Models\Setting::get('ga_measurement_id', 'G-7SSS8SELE3');
    $adsClient = \App\Models\Setting::get('adsense_client', config('services.adsense.client'));
  @endphp
  {{-- Google Analytics (gtag.js) — loaded immediately so every visit is counted. --}}
  @if(filled($gaId))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){ dataLayer.push(arguments); }
      gtag('js', new Date());
      gtag('config', '{{ $gaId }}');
    </script>
  @endif
  {{-- AdSense — deferred to browser idle / first interaction (kept off the
       critical path; it's heavier and not time-sensitive). --}}
  @if(filled($adsClient))
    <script>
      (function () {
        var done = false;
        function load() {
          if (done) return; done = true;
          var ad = document.createElement('script');
          ad.async = true; ad.crossOrigin = 'anonymous';
          ad.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsClient }}';
          document.head.appendChild(ad);
        }
        if ('requestIdleCallback' in window) { requestIdleCallback(load, { timeout: 3000 }); }
        else { setTimeout(load, 2500); }
        ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (e) {
          window.addEventListener(e, load, { once: true, passive: true });
        });
      })();
    </script>
  @endif

  <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" />
  <link rel="icon" href="{{ asset('icon-32.png') }}" sizes="32x32" type="image/png" />
  <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" />
  <meta name="theme-color" content="#e33b4e" />
  {{-- PWA / installability — required for web push on iOS (Home Screen app) --}}
  <link rel="manifest" href="{{ asset('site.webmanifest') }}" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="TheTrueDefender" />
  @php
    // $pageSeo (a PageSeo row) is shared by the layouts.app view composer for
    // static pages; its admin-set meta overrides the view's @section defaults.
    $pageSeo = $pageSeo ?? null;
    $seoTitle = ($pageSeo?->meta_title)
        ? (str_contains($pageSeo->meta_title, 'TheTrueDefender')
            ? $pageSeo->meta_title
            : $pageSeo->meta_title . ' — TheTrueDefender')
        : (trim(View::yieldContent('title')) ?: 'TheTrueDefender — Independent News');
    $seoDesc = $pageSeo?->meta_description
        ?: (trim(View::yieldContent('meta_description'))
            ?: 'TheTrueDefender — Independent news covering Politics, US News, World, Story of Hope and Opinion.');
  @endphp
  <title>{{ $seoTitle }}</title>
  <meta name="description" content="{{ $seoDesc }}" />
  <link rel="canonical" href="@yield('canonical', url()->current())" />

  {{-- Open Graph / Twitter cards --}}
  <meta property="og:site_name" content="{{ config('app.name', 'TheTrueDefender') }}" />
  <meta property="og:type" content="@yield('og_type', 'website')" />
  <meta property="og:title" content="@yield('og_title', $seoTitle)" />
  <meta property="og:description" content="@yield('og_description', $seoDesc)" />
  <meta property="og:url" content="@yield('canonical', url()->current())" />
  @hasSection('og_image')
    <meta property="og:image" content="@yield('og_image')" />
    <meta name="twitter:image" content="@yield('og_image')" />
    <meta name="twitter:card" content="summary_large_image" />
  @else
    <meta name="twitter:card" content="summary" />
  @endif
  <meta name="twitter:title" content="@yield('og_title', $seoTitle)" />
  <meta name="twitter:description" content="@yield('og_description', $seoDesc)" />
  @stack('head')

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  @php $fontsHref = 'https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;700;800;900&family=Inter:wght@400;500;600&display=swap'; @endphp
  {{-- Load fonts without blocking render (swap to stylesheet once fetched). --}}
  <link rel="preload" as="style" href="{{ $fontsHref }}" onload="this.onload=null;this.rel='stylesheet'" />
  <noscript><link rel="stylesheet" href="{{ $fontsHref }}" /></noscript>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ @filemtime(public_path('css/style.css')) ?: '1' }}" />
</head>
<body>

  <div class="bg-fx" aria-hidden="true">
    <canvas id="bgCanvas"></canvas>
    <div class="bg-aura bg-aura-1"></div>
    <div class="bg-aura bg-aura-2"></div>
  </div>

  @include('partials.ticker')
  @include('partials.nav')

  @yield('content')

  @include('partials.footer')
  @include('partials.consent')
  @include('partials.cart-drawer')

  {{-- PWA install nudge (subtle bottom bar; shown once, after cookies handled) --}}
  <div class="install-banner" id="installBanner" hidden>
    <span class="install-text">📱 Add TheTrueDefender to your home screen for one-tap access.</span>
    <span class="install-actions">
      <button type="button" id="installBtn" class="install-yes">Install</button>
      <button type="button" id="installDismiss" class="install-no" aria-label="Dismiss">✕</button>
    </span>
  </div>

  <script src="{{ asset('js/main.js') }}?v={{ @filemtime(public_path('js/main.js')) ?: '1' }}"></script>
  <script src="{{ asset('js/audience.js') }}?v={{ @filemtime(public_path('js/audience.js')) ?: '1' }}"></script>
  @stack('scripts')
</body>
</html>
