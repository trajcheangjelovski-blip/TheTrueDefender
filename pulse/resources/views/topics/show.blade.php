@extends('layouts.app')

@section('title', $tag->name . ' — News & Updates — TheTrueDefender')
@section('meta_description', $tag->description ?: ('All the latest ' . $tag->name . ' news, analysis and updates from TheTrueDefender.'))
@section('canonical', route('topic.show', $tag))

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $tag->name,
    'description' => $tag->description ?: ('Latest ' . $tag->name . ' coverage.'),
    'url' => route('topic.show', $tag),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
  <main class="page-main" style="max-width:1360px">
    <nav class="article-crumb" aria-label="Breadcrumb" style="margin-top:10px">
      <a href="{{ route('home') }}">Home</a>
      <span>/</span>
      <a href="{{ route('topics.index') }}">Topics</a>
      <span>/</span>
      <span>{{ $tag->name }}</span>
    </nav>

    <div class="section-head" style="margin-top:10px">
      <h2><span class="head-accent">#</span> {{ $tag->name }}</h2>
      <div class="head-line"></div>
    </div>

    @if($tag->description)
      <p class="page-sub">{{ $tag->description }}</p>
    @else
      <p class="page-sub">Every {{ $tag->name }} story we've covered — newest first.</p>
    @endif

    <div class="overlay-grid" style="margin-top:20px">
      @forelse($posts as $p)
        @php $color = $p->category?->color ?? '#e33b4e'; @endphp
        <a href="{{ route('post.show', $p) }}" class="story-card tilt-card ov-card" data-tilt>
          @include('partials.postimg', ['post' => $p, 'class' => 'story-bg', 'grad' => 'background: linear-gradient(135deg, ' . $color . '33, #0b0910)'])
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            @if($p->category)
              <span class="badge" style="background:{{ $color }};color:#fff">{{ strtoupper($p->category->name) }}</span>
            @endif
            <h3>{{ $p->title }}</h3>
            <span class="meta-time">By {{ $p->public_author }} · {{ $p->time_ago }}</span>
          </div>
        </a>
      @empty
        <p style="color:var(--text-dim)">No stories on this topic yet.</p>
      @endforelse
    </div>

    @include('partials.ad', ['placement' => 'category_list'])

    <div style="margin-top:36px">
      {{ $posts->links() }}
    </div>

    @if($related->isNotEmpty())
      <section class="section" style="margin-top:44px">
        <div class="section-head">
          <h2><span class="head-accent">↦</span> Related topics</h2>
          <div class="head-line"></div>
        </div>
        <div class="topic-cloud">
          @foreach($related as $r)
            <a href="{{ route('topic.show', $r) }}" class="topic-chip">{{ $r->name }} <span class="topic-chip-count">{{ $r->stories_count }}</span></a>
          @endforeach
        </div>
      </section>
    @endif
  </main>
@endsection
