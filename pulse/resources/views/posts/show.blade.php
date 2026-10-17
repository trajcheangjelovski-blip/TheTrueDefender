@extends('layouts.app')

@section('title', str_contains($post->meta_title ?: $post->title, 'TheTrueDefender') ? ($post->meta_title ?: $post->title) : ($post->meta_title ?: $post->title) . ' — TheTrueDefender')
@section('meta_description', $post->meta_description ?: $post->excerpt)
@section('canonical', route('post.show', $post))
@section('og_type', 'article')
@section('og_title', $post->meta_title ?: $post->title)
@section('og_description', $post->meta_description ?: $post->excerpt)
@if($post->featured_image)
  @section('og_image', $post->shareImageUrl())
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
    'author' => $post->public_author_is_person
        ? array_filter([
            '@type' => 'Person',
            'name' => $post->public_author,
            'url' => $post->public_author_url,
            'jobTitle' => $post->public_author_title,
        ])
        : ['@type' => 'Organization', 'name' => $post->public_author],
    'publisher' => [
        '@type' => 'Organization',
        'name' => config('app.name', 'TheTrueDefender'),
        'logo' => [
            '@type' => 'ImageObject',
            'url' => asset('icon-512.png'),
            'width' => 512,
            'height' => 512,
        ],
    ],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => route('post.show', $post)],
    'articleSection' => $post->category?->name,
    'keywords' => $post->tags->pluck('name')->implode(', ') ?: null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
{{-- No FAQPage schema on news: Google limits FAQ rich results to authoritative
     government/health sites, and an auto-FAQ on every story reads as written for
     search engines rather than readers. --}}
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
    $topicAttr = $post->tags->map(fn ($t) => $t->slug . '::' . $t->name)->implode('|');
  @endphp

  <div class="read-progress" id="readProgress" aria-hidden="true"></div>

  <article class="article"
    data-article-slug="{{ $post->slug }}"
    data-article-category="{{ $post->category?->slug }}"
    data-article-topics="{{ $topicAttr }}">

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
          <a class="article-author" href="{{ $post->public_author_url }}" style="color:inherit" rel="author">{{ $post->public_author }}@if($post->public_author_title) <span style="opacity:.6">— {{ $post->public_author_title }}</span>@endif</a>
          <span class="dot">·</span>
          <span>{{ optional($post->published_at)?->timezone(config('app.display_timezone'))->format('M j, Y') }}</span>
          <span class="dot">·</span>
          <span>{{ $post->reading_minutes }} min read</span>
          @if($post->allow_comments)
            @php $cCount = $post->comments()->where('status', 'approved')->count(); @endphp
            @if($cCount > 0)
              <span class="dot">·</span>
              <a href="#comments" style="color:inherit">💬 {{ $cCount }}</a>
            @endif
          @endif
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

        @if(!empty($post->takeaways))
          <aside class="takeaways" aria-label="Key takeaways">
            <div class="takeaways-head"><span class="takeaways-icon">📌</span> Key Takeaways</div>
            <ul>
              @foreach($post->takeaways as $point)
                @php $pt = is_array($point) ? ($point['text'] ?? '') : $point; @endphp
                <li>{!! \App\Support\TopicLinker::link($pt, $post->tags) !!}</li>
              @endforeach
            </ul>
          </aside>
        @endif

        <div class="article-body first">
          {!! $bodyFirst !!}
        </div>

        {{-- Reading path kept clean: no repeated pull-quote / copy-for-social box
             and no mid-article newsletter ask. Sharing lives in the side rail; the
             single newsletter ask sits at the end of the article. --}}
        @if($bodySecond)
          @include('partials.ad', ['placement' => 'article_mid'])

          <div class="article-body">
            {!! $bodySecond !!}
          </div>
        @endif

        @include('partials.ad', ['placement' => 'article_end'])

        {{-- Auto-generated FAQs are no longer shown on news articles (they read as
             search padding). The topic hub link stays — it's real internal linking. --}}
        @if($primaryTopic ?? null)
          <a class="faq-more" href="{{ route('topic.show', $primaryTopic) }}">
            Full coverage: {{ $primaryTopic->name }} <span aria-hidden="true">→</span>
          </a>
        @endif

        @include('partials.tags', ['post' => $post])

        {{-- The public Sources list has been removed. Stories are still built from
             multiple independent sources (recorded in $post->sources and enforced
             by PublishGate) — that attribution is just no longer displayed. --}}

        {{-- Follow: developing-story alert + follow the story's main topic.
             Follows are stored per-device (no account); breaking alerts are
             delivered globally today (per-topic delivery is a backend TODO). --}}
        @if($post->is_developing || ($primaryTopic ?? null))
          <div class="story-follow-row">
            @if($post->is_developing)
              <div class="story-follow-dev">
                <button class="topic-follow-btn topic-follow-btn--story" data-follow-topic data-follow-story
                        data-topic-slug="story-{{ $post->slug }}" data-topic-name="{{ \Illuminate\Support\Str::limit($post->title, 48) }}">
                  🔔 Follow this story
                </button>
                <span class="story-follow-note">This is a developing story — get notified if there are major developments.</span>
              </div>
            @endif
            @if($primaryTopic ?? null)
              <button class="topic-follow-btn" data-follow-topic
                      data-topic-slug="{{ $primaryTopic->slug }}" data-topic-name="{{ $primaryTopic->name }}">
                <span class="tf-plus">＋</span> Follow {{ $primaryTopic->name }}
              </button>
            @endif
          </div>
        @endif

        {{-- Up Next: the single strongest "keep reading" pull, to beat the
             one-article bounce. Auto-tracked as a headline click. --}}
        @if($upNext ?? null)
          @php $unColor = $upNext->category?->color ?? '#e33b4e'; @endphp
          <a href="{{ route('post.show', $upNext) }}" class="up-next">
            <div class="up-next-media">
              @include('partials.postimg', ['post' => $upNext, 'class' => 'up-next-bg', 'size' => 'card', 'grad' => 'background: linear-gradient(135deg, ' . $unColor . '33, #0b0910)'])
            </div>
            <div class="up-next-body">
              <span class="up-next-label">Up Next @if($upNext->category)· in {{ $upNext->category->name }}@endif</span>
              <h3 class="up-next-title">{{ $upNext->title }}</h3>
              <span class="up-next-meta">{{ $upNext->reading_minutes }} min read</span>
              <span class="up-next-cta">Continue reading <span aria-hidden="true">→</span></span>
            </div>
          </a>
        @endif

        {{-- End-of-article capture (highest-intent moment) — The Morning Brief.
             The only newsletter ask in the article. --}}
        <div data-hide-if-subscribed>
          @include('partials.newsletter', ['variant' => 'compact', 'source' => 'article_end'])
        </div>
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
