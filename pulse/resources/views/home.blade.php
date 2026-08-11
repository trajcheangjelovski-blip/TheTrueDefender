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
  {{-- Returning-visitor welcome — populated client-side from last-visit state.
       Honest + non-creepy: only a count of new stories, never tracking details. --}}
  <div class="return-welcome" id="returnWelcome" hidden>
    <span class="return-welcome-text" id="returnWelcomeText"></span>
    <button type="button" class="return-welcome-x" id="returnWelcomeX" aria-label="Dismiss">✕</button>
  </div>
  <script type="application/json" id="ttdRecentTimes">@json($recentTimes)</script>

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

  {{-- ☕ Morning Brief — the day's must-know stories (auto-updates; hides if empty) --}}
  @if($brief->isNotEmpty())
    <section class="section reveal">
      <div class="section-head">
        <h2><span class="head-accent">☕</span> Morning Brief</h2>
        <div class="head-line"></div>
        <span class="head-link" style="color:var(--text-dim)">{{ $brief->count() }} things to know today</span>
      </div>
      <ol class="brief-list">
        @foreach($brief as $b)
          @php $bc = $b->category?->color ?? '#e33b4e'; @endphp
          <li class="brief-item">
            <span class="brief-num" style="background:linear-gradient(135deg,{{ $bc }},#1a1030)">{{ $loop->iteration }}</span>
            <a href="{{ route('post.show', $b) }}" class="brief-body">
              <span class="brief-title">{{ $b->title }}</span>
              @if($b->excerpt)<span class="brief-context">{{ \Illuminate\Support\Str::limit($b->excerpt, 110) }}</span>@endif
            </a>
          </li>
        @endforeach
      </ol>
    </section>
  @endif

  {{-- Daily quiz teaser — streak-aware (enhanced client-side from localStorage) --}}
  @if($hasQuiz)
    <section class="section reveal">
      <a href="{{ route('quiz') }}" class="quiz-teaser" data-quiz-teaser>
        <span class="quiz-teaser-icon" data-qt-icon>🧠</span>
        <span class="quiz-teaser-text">
          <strong data-qt-title>Today's News Quiz</strong>
          <span data-qt-sub>Think you followed today's biggest stories? 5 questions · about 2 minutes.</span>
        </span>
        <span class="quiz-teaser-cta" data-qt-cta>Take Today's Quiz →</span>
      </a>
    </section>
  @endif

  {{-- Most read this week + reader poll --}}
  @if($mostRead->isNotEmpty() || $poll)
    <section class="section reveal engage-row">
      @if($mostRead->isNotEmpty())
        <div class="engage-col">
          <div class="section-head"><h2><span class="head-accent">📈</span> Most Read This Week</h2><div class="head-line"></div></div>
          <ol class="mostread-list">
            @foreach($mostRead as $mr)
              <li>
                <span class="mostread-rank">{{ $loop->iteration }}</span>
                <a href="{{ route('post.show', $mr) }}">{{ $mr->title }}</a>
              </li>
            @endforeach
          </ol>
        </div>
      @endif
      @if($poll)
        <div class="engage-col">
          <div class="section-head"><h2><span class="head-accent">🗳️</span> Reader Poll</h2><div class="head-line"></div></div>
          @include('partials.poll', ['poll' => $poll])
        </div>
      @endif
    </section>
  @endif

  {{-- Compact Morning Brief capture — placed after the first engagement block
       (Top Stories → Trending → Quiz → Most Read), before the reader goes deep.
       Naturally in the feed, not a hero. Hidden for existing subscribers via JS. --}}
  <section class="section reveal" data-hide-if-subscribed>
    @include('partials.newsletter', ['variant' => 'compact', 'source' => 'homepage_mid'])
  </section>

  {{-- Most discussed — pulls readers back to active conversations --}}
  @if($mostDiscussed->isNotEmpty())
    <section class="section reveal">
      <div class="section-head"><h2><span class="head-accent">💬</span> Most Discussed</h2><div class="head-line"></div></div>
      <ol class="mostread-list discuss-list">
        @foreach($mostDiscussed as $md)
          <li>
            <span class="mostread-rank">{{ $loop->iteration }}</span>
            <div class="discuss-body">
              <a href="{{ route('post.show', $md) }}#comments">{{ $md->title }}</a>
              @if($md->topApprovedComment)
                <p class="discuss-quote">“{{ \Illuminate\Support\Str::limit(strip_tags($md->topApprovedComment->body), 120) }}”
                  <span class="discuss-author">— {{ $md->topApprovedComment->display_name }}</span></p>
              @endif
            </div>
            <span class="discuss-count">💬 {{ $md->comments_count }}</span>
          </li>
        @endforeach
      </ol>
    </section>
  @endif

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
        <span class="head-icon" style="background:#c7962a1f; border-color:#c7962a55">🎁</span>
        Free Patriot Gifts
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, #c7962a66, transparent)"></div>
      <a href="{{ route('shop.index') }}" class="head-link" style="color:#e0b04b">See all →</a>
    </div>
    <p class="page-sub" style="margin:-6px 0 18px">These gifts are on us — you just cover shipping. Every order helps keep independent journalism free and unfiltered.</p>
    <div class="shop-grid" id="shopGrid">
      @foreach($shopProducts as $product)
        @include('partials.product-card', ['product' => $product])
      @endforeach
    </div>
  </section>

  {{-- Newsletter — The Defender Morning Brief (named editorial product) --}}
  <section class="newsletter reveal">
    @include('partials.newsletter', ['variant' => 'full', 'source' => 'homepage_bottom'])

    <div style="max-width:1100px;margin:22px auto 0">
      @include('partials.follow')
    </div>
  </section>
@endsection
