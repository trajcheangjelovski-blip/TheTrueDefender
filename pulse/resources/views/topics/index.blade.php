@extends('layouts.app')

@section('title', 'Topics — Browse by Subject — TheTrueDefender')
@section('meta_description', 'Browse TheTrueDefender by topic — politics, world events, people and the issues shaping America.')
@section('canonical', route('topics.index'))

@section('content')
  <main class="page-main" style="max-width:1360px">
    <div class="section-head" style="margin-top:10px">
      <h2><span class="head-accent">#</span> Topics</h2>
      <div class="head-line"></div>
    </div>
    <p class="page-sub">Follow the subjects you care about. Every topic collects our full coverage in one place.</p>

    @if($tags->isEmpty())
      <p style="color:var(--text-dim)">Topics will appear here as stories are published.</p>
    @else
      <div class="topic-cloud" style="margin-top:20px">
        @foreach($tags as $tag)
          <a href="{{ route('topic.show', $tag) }}" class="topic-chip">{{ $tag->name }} <span class="topic-chip-count">{{ $tag->stories_count }}</span></a>
        @endforeach
      </div>
    @endif
  </main>
@endsection
