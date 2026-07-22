@extends('layouts.app')

@php $lcp = $featured->first(); @endphp
@if($lcp && ($lcpUrl = $lcp->imageUrl('hero')))
  @push('head')
    <link rel="preload" as="image" href="{{ $lcpUrl }}"
          @if($ss = $lcp->imageSrcset()) imagesrcset="{{ $ss }}" imagesizes="100vw" @endif
          fetchpriority="high" />
  @endpush
@endif

@section('content')
  <section class="hero" id="home">
    <div class="hero-bg">
      <div class="orb orb-1"></div>
      <div class="orb orb-2"></div>
      <div class="orb orb-3"></div>
      <div class="grid-floor"></div>
    </div>

    <div class="hero-top">
      <div class="hero-eyebrow">
        <span class="live-chip"><i></i>TOP STORIES</span>
        <span class="hero-date" id="heroDate"></span>
      </div>
    </div>

    <div class="slider" id="heroSlider">
      <div class="slider-track" id="sliderTrack">
        @foreach($featured as $post)
          @php $c = $post->category; @endphp
          <article class="slide {{ $loop->first ? 'active' : '' }}">
            @include('partials.postimg', ['post' => $post, 'class' => 'slide-bg', 'size' => 'hero', 'eager' => $loop->first, 'grad' => 'background: linear-gradient(135deg, ' . ($c?->color ?? '#e33b4e') . '33, #0b0910)'])
            <div class="slide-scrim"></div>
            <div class="slide-content">
              <div class="meta-row">
                <span class="badge" style="background:{{ $c?->color ?? '#e33b4e' }};color:#fff">{{ strtoupper($c?->name ?? 'News') }}</span>
                <span class="meta-time">{{ $post->time_ago }}</span>
              </div>
              @if($loop->first)
                <h1>{{ $post->title }}</h1>
              @else
                <h2>{{ $post->title }}</h2>
              @endif
              <p>{{ $post->excerpt }}</p>
              <div class="story-cta-row">
                <a class="story-cta" href="{{ route('post.show', $post) }}">Read Full Story <span aria-hidden="true">→</span></a>
                <span class="story-author">By {{ $post->public_author }}</span>
              </div>
            </div>
          </article>
        @endforeach
      </div>

      <button class="slider-arrow prev" id="sliderPrev" aria-label="Previous story">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
      </button>
      <button class="slider-arrow next" id="sliderNext" aria-label="Next story">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
      </button>

      <div class="slider-footer">
        <div class="slider-dots" id="sliderDots"></div>
        <span class="slider-count" id="sliderCount">01 / {{ str_pad($featured->count(), 2, '0', STR_PAD_LEFT) }}</span>
      </div>
    </div>
  </section>

  {{-- Trending --}}
  <section class="section trending reveal">
    <div class="section-head">
      <h2><span class="head-accent">🔥</span> Trending Now</h2>
      <div class="head-line"></div>
    </div>
    <div class="trend-row">
      @foreach($trending as $i => $post)
        @php $c = $post->category; @endphp
        <a href="{{ route('post.show', $post) }}" class="trend-item">
          <span class="trend-rank">{{ $i + 1 }}</span>
          <div class="trend-body">
            <h4>@if($post->is_trending_now)<span title="Trending">🔥</span> @endif{{ $post->title }}</h4>
            <span class="trend-meta"><i style="background:{{ $c?->color ?? '#e33b4e' }}"></i>{{ $c?->name ?? 'News' }} · {{ $post->time_ago }}</span>
          </div>
        </a>
      @endforeach
    </div>
  </section>

  <div class="section" style="padding-top:0;padding-bottom:0">
    @include('partials.ad', ['placement' => 'home_top'])
  </div>

  {{-- Category sections --}}
  <main id="categorySections">
    @foreach($sections as $section)
      @include('partials.category', ['cat' => $section['cat'], 'posts' => $section['posts']])
    @endforeach
  </main>

  {{-- Shop (dynamic — from the database) --}}
  <section class="section reveal" id="shop">
    <div class="section-head">
      <h2>
        <span class="head-icon" style="background:#c7962a1f; border-color:#c7962a55">🛍️</span>
        Patriot Shop
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, #c7962a66, transparent)"></div>
      <a href="{{ route('shop.index') }}" class="head-link" style="color:#e0b04b">See all →</a>
    </div>
    <p class="page-sub" style="margin:-6px 0 18px">Patriot-themed gear, shipping &amp; handling included in the price shown. Every order helps keep our journalism independent.</p>
    <div class="shop-grid" id="shopGrid">
      @foreach($shopProducts as $product)
        @include('partials.product-card', ['product' => $product])
      @endforeach
    </div>
  </section>

  {{-- Newsletter --}}
  <section class="newsletter reveal">
    <div class="newsletter-card tilt-card" data-tilt>
      <div class="card-glare"></div>
      <div class="nl-text">
        <h2>Never Miss a Story</h2>
        <p>Get the most important headlines delivered to your inbox every morning. No spam, just news.</p>
      </div>
      <form class="nl-form" data-subscribe data-source="newsletter">
        <input type="email" name="email" placeholder="your@email.com" required />
        <button type="submit">Sign Up Free</button>
      </form>
    </div>
  </section>
@endsection
