@extends('layouts.app')

@section('title', $author->name . ($author->title ? ' — ' . $author->title : '') . ' | TheTrueDefender')
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags($author->bio ?: ($author->name . ' at TheTrueDefender.')), 155))
@section('canonical', route('author.show', $author->slug))

@push('head')
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'mainEntity' => array_filter([
        '@type' => 'Person',
        'name' => $author->name,
        'url' => route('author.show', $author->slug),
        'jobTitle' => $author->title,
        'description' => $author->bio ? strip_tags($author->bio) : null,
        'image' => $author->photoUrl(),
        'sameAs' => array_values(array_filter([$author->x_url, $author->linkedin_url])) ?: null,
        'worksFor' => ['@type' => 'Organization', 'name' => config('app.name', 'TheTrueDefender')],
    ]),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
  <main class="page-main" style="max-width:1100px">

    {{-- ── Author profile header ── --}}
    <section class="author-hero" style="display:flex;gap:22px;align-items:flex-start;margin:18px 0 10px;padding:22px;border:1px solid var(--line,#2a2340);border-radius:16px;background:var(--surface,#140f22)">
      <div style="flex:0 0 auto">
        @if($author->photoUrl())
          <img src="{{ $author->photoUrl() }}" alt="{{ $author->name }}" width="96" height="96"
               style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid var(--accent-2,#e33b4e)">
        @else
          <span style="display:grid;place-items:center;width:96px;height:96px;border-radius:50%;font-weight:800;font-size:1.8rem;color:#fff;background:linear-gradient(135deg,#e33b4e,#1a1030)">
            {{ \Illuminate\Support\Str::of($author->name)->explode(' ')->map(fn($w)=>mb_substr($w,0,1))->take(2)->implode('') }}
          </span>
        @endif
      </div>

      <div style="flex:1 1 auto;min-width:0">
        <h1 style="margin:0 0 2px;font-size:1.7rem">{{ $author->name }}</h1>
        @if($author->title)
          <div style="color:var(--accent-2,#e33b4e);font-weight:700;letter-spacing:.02em">{{ $author->title }}</div>
        @endif
        @if($author->bio)
          <p style="margin:12px 0 0;color:var(--text,#e8e4f0);line-height:1.6">{{ $author->bio }}</p>
        @endif
        @if($author->credentials)
          <p style="margin:10px 0 0;color:var(--text-dim,#9b93b5);font-size:.95rem;line-height:1.55">{{ $author->credentials }}</p>
        @endif

        <div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:.9rem">
          @if($author->x_url)
            <a href="{{ $author->x_url }}" target="_blank" rel="me noopener" style="color:inherit">𝕏 / X</a>
          @endif
          @if($author->linkedin_url)
            <a href="{{ $author->linkedin_url }}" target="_blank" rel="me noopener" style="color:inherit">LinkedIn</a>
          @endif
          <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--text-dim,#9b93b5)">Editorial Standards</a>
        </div>
      </div>
    </section>

    <div class="section-head" style="margin-top:26px">
      <h2>Stories by {{ $author->name }}</h2>
      <div class="head-line" style="background:linear-gradient(90deg, var(--accent-2,#e33b4e)66, transparent)"></div>
    </div>

    <div class="overlay-grid" style="margin-top:20px">
      @forelse($posts as $p)
        <a href="{{ route('post.show', $p) }}" class="story-card tilt-card ov-card" data-tilt>
          @include('partials.postimg', ['post' => $p, 'class' => 'story-bg', 'grad' => 'background: linear-gradient(135deg, ' . ($p->category?->color ?? '#e33b4e') . '33, #0b0910)'])
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            @if($p->category)
              <span class="badge" style="background:{{ $p->category->color }};color:#fff">{{ strtoupper($p->category->name) }}</span>
            @endif
            <h3>{{ $p->title }}</h3>
            <span class="meta-time">{{ $p->time_ago }}</span>
          </div>
        </a>
      @empty
        <p style="color:var(--text-dim)">No published stories yet.</p>
      @endforelse
    </div>

    <div style="margin-top:36px">
      {{ $posts->links() }}
    </div>
  </main>
@endsection
