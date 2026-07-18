@extends('layouts.app')

@section('title', $category->name . ' — TheTrueDefender')

@section('content')
  <main class="page-main" style="max-width:1360px">
    <div class="section-head" style="margin-top:10px">
      <h2>
        <span class="head-icon" style="background:{{ $category->color }}1f; border-color:{{ $category->color }}55">{{ $category->icon }}</span>
        {{ $category->name }}
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, {{ $category->color }}66, transparent)"></div>
    </div>

    @if($category->description)
      <p class="page-sub">{{ $category->description }}</p>
    @endif

    <div class="overlay-grid" style="margin-top:20px">
      @forelse($posts as $p)
        <a href="{{ route('post.show', $p) }}" class="story-card tilt-card ov-card" data-tilt>
          @include('partials.postimg', ['post' => $p, 'class' => 'story-bg', 'grad' => 'background: linear-gradient(135deg, ' . $category->color . '33, #0b0910)'])
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            <span class="badge" style="background:{{ $category->color }};color:#fff">{{ strtoupper($category->name) }}</span>
            <h3>{{ $p->title }}</h3>
            <span class="meta-time">By {{ $p->author?->name ?? 'Staff' }} · {{ $p->time_ago }}</span>
          </div>
        </a>
      @empty
        <p style="color:var(--text-dim)">No stories published in this category yet.</p>
      @endforelse
    </div>

    @include('partials.ad', ['placement' => 'category_list'])

    <div style="margin-top:36px">
      {{ $posts->links() }}
    </div>
  </main>
@endsection
