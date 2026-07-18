<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
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
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('css/style.css') }}" />
  @php $adsClient = \App\Models\Setting::get('adsense_client', config('services.adsense.client')); @endphp
  @if(filled($adsClient))
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsClient }}" crossorigin="anonymous"></script>
  @endif
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

  <script src="{{ asset('js/main.js') }}"></script>
  <script src="{{ asset('js/audience.js') }}"></script>
  @stack('scripts')
</body>
</html>
