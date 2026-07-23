@extends('layouts.app')

@section('title', str_contains($post->meta_title ?: $post->title, 'TheTrueDefender') ? ($post->meta_title ?: $post->title) : ($post->meta_title ?: $post->title) . ' — TheTrueDefender')
@section('meta_description', $post->meta_description ?: $post->excerpt)
@section('canonical', route('post.show', $post))
@section('og_type', 'article')
@section('og_title', $post->meta_title ?: $post->title)
@section('og_description', $post->meta_description ?: $post->excerpt)
@if($post->featured_image)
  @section('og_image', asset('storage/' . $post->featured_image))
@endif

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => $post->title,
    'description' => $post->meta_description ?: $post->excerpt,
    'image' => $post->featured_image ? [asset('storage/' . $post->featured_image)] : [],
    'datePublished' => optional($post->published_at)->toIso8601String(),
    'dateModified' => optional($post->updated_at)->toIso8601String(),
    'author' => ['@type' => 'Organization', 'name' => $post->public_author],
    'publisher' => [
        '@type' => 'Organization',
        'name' => config('app.name', 'TheTrueDefender'),
    ],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => route('post.show', $post)],
    'articleSection' => $post->category?->name,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
  @php
    $c = $post->category;
    $color = $c?->color ?? '#e33b4e';
    $shareUrl = route('post.show', $post);
    $shareText = rawurlencode($post->title);
    $shareLink = rawurlencode($shareUrl);
    [$bodyFirst, $bodySecond] = $post->bodyParts();
    $initials = $post->public_author_initials;
  @endphp

  <div class="read-progress" id="readProgress" aria-hidden="true"></div>

  <article class="article">

    {{-- ── Cinematic hero ── --}}
    <header class="article-hero">
      <div class="article-hero-bg" style="background: linear-gradient(135deg, {{ $color }}44, #0b0910)">
        @if($url = $post->imageUrl('hero'))
          <img src="{{ $url }}" alt="{{ $post->title }}" />
          <span class="img-logo">
            <span class="img-logo-mark">TTD</span>
            <span class="img-logo-text">The True <em>Defender</em></span>
          </span>
        @else
          <span class="ph-icon">{{ $post->image_icon }}</span>
        @endif
      </div>
      <div class="article-hero-scrim"></div>

      <div class="article-hero-inner">
        <nav class="article-crumb" aria-label="Breadcrumb">
          <a href="{{ route('home') }}">Home</a>
          @if($c)
            <span>/</span>
            <a href="{{ route('category.show', $c) }}">{{ $c->name }}</a>
          @endif
        </nav>

        @if($post->is_breaking_now)
          <span style="display:inline-block;background:#e0143c;color:#fff;font-weight:800;font-size:.72rem;letter-spacing:.08em;padding:5px 12px;border-radius:6px;margin-bottom:12px;text-transform:uppercase">⚡ Breaking</span>
        @endif

        <h1 class="article-title">{{ $post->title }}</h1>

        <div class="article-meta">
          <span class="avatar" style="background:linear-gradient(135deg, {{ $color }}, #1a1030)">{{ strtoupper($initials) }}</span>
          <a class="article-author" href="{{ route('page', 'editorial-standards') }}" style="color:inherit">{{ $post->public_author }} <span style="opacity:.6">— Editorial Team</span></a>
          <span class="dot">·</span>
          <span>{{ optional($post->published_at)?->timezone(config('app.display_timezone'))->format('M j, Y') }}</span>
          <span class="dot">·</span>
          <span>{{ $post->reading_minutes }} min read</span>
        </div>
      </div>
    </header>

    {{-- ── Body with share rail ── --}}
    <div class="article-wrap">
      <aside class="share-rail" aria-label="Share this story">
        <a class="share-btn" target="_blank" rel="noopener nofollow" aria-label="Share on X"
           href="https://twitter.com/intent/tweet?text={{ $shareText }}&url={{ $shareLink }}">𝕏</a>
        <a class="share-btn" target="_blank" rel="noopener nofollow" aria-label="Share on Facebook"
           href="https://www.facebook.com/sharer/sharer.php?u={{ $shareLink }}">f</a>
        <a class="share-btn" target="_blank" rel="noopener nofollow" aria-label="Share on Truth Social"
           href="https://truthsocial.com/share?text={{ $shareText }}&url={{ $shareLink }}">T</a>
        <a class="share-btn" target="_blank" rel="noopener nofollow" aria-label="Share on Telegram"
           href="https://t.me/share/url?url={{ $shareLink }}&text={{ $shareText }}"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg></a>
        <button class="share-btn" type="button" aria-label="Copy link" data-copy="{{ $shareUrl }}">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </button>
      </aside>

      <div class="article-main">
        @if($post->excerpt)
          <p class="article-lead">{{ $post->excerpt }}</p>
        @endif

        <div class="article-body first">
          {!! $bodyFirst !!}
        </div>

        @if($bodySecond)
          @include('partials.ad', ['placement' => 'article_mid'])

          <div class="article-body">
            {!! $bodySecond !!}
          </div>
        @endif

        @include('partials.ad', ['placement' => 'article_end'])
      </div>
    </div>

    @if($post->allow_comments)
      @include('partials.comments', ['post' => $post])
    @endif

    {{-- ── Related stories ── --}}
    @if($related->isNotEmpty())
      <section class="section article-related">
        <div class="section-head">
          <h2><span class="head-accent">↺</span> More from {{ $c?->name ?? 'TheTrueDefender' }}</h2>
          <div class="head-line"></div>
          @if($c)
            <a href="{{ route('category.show', $c) }}" class="head-link" style="color:{{ $color }}">View all →</a>
          @endif
        </div>
        <div class="overlay-grid">
          @foreach($related as $p)
            <a href="{{ route('post.show', $p) }}" class="story-card tilt-card ov-card" data-tilt>
              @include('partials.postimg', ['post' => $p, 'class' => 'story-bg', 'grad' => 'background: linear-gradient(135deg, ' . $color . '33, #0b0910)'])
              <div class="story-scrim"></div>
              <div class="card-glare"></div>
              <div class="story-content">
                <h3>{{ $p->title }}</h3>
                <span class="meta-time">{{ $p->time_ago }}</span>
              </div>
            </a>
          @endforeach
        </div>
      </section>
    @endif
  </article>
@endsection
