@extends('layouts.app')

@section('title', 'Opinion Forum — TheTrueDefender')
@section('meta_description', 'Join the debate. Read the questions of the day and share your opinion with the TheTrueDefender community.')

@section('content')
  <main class="page-main" style="max-width:960px">
    <div class="section-head" style="margin-top:10px">
      <h2>
        <span class="head-icon" style="background:{{ $category->color }}1f; border-color:{{ $category->color }}55">{{ $category->icon ?? '💬' }}</span>
        Opinion — Join the Discussion
      </h2>
      <div class="head-line" style="background:linear-gradient(90deg, {{ $category->color }}66, transparent)"></div>
    </div>

    <p class="page-sub">Pick a topic below and have your say. Every opinion is reviewed before it appears — keep it respectful and on point.</p>

    <div class="forum-board" style="display:flex;flex-direction:column;gap:12px;margin-top:22px">
      @forelse($posts as $p)
        @php
          $replies = (int) ($p->replies_count ?? 0);
          $last = $p->last_reply_at ? \Illuminate\Support\Carbon::parse($p->last_reply_at) : $p->published_at;
        @endphp
        <a href="{{ route('post.show', $p) }}"
           style="display:flex;gap:16px;align-items:center;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:18px 20px;transition:border-color .2s,transform .2s"
           onmouseover="this.style.borderColor='{{ $category->color }}88';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='rgba(255,255,255,.08)';this.style.transform='none'">
          @php $thumb = $p->imageUrl('thumb'); @endphp
          <span style="flex:0 0 auto;width:64px;height:64px;border-radius:12px;overflow:hidden;display:grid;place-items:center;font-size:1.4rem;background:linear-gradient(135deg,{{ $category->color }}44,#0b0910)">
            @if($thumb)
              <img src="{{ $thumb }}" alt="{{ $p->title }}" loading="lazy" style="width:100%;height:100%;object-fit:cover" />
            @else
              {{ $p->image_icon ?: '💬' }}
            @endif
          </span>
          <div style="flex:1;min-width:0">
            <h3 style="font-size:1.05rem;font-weight:700;margin:0 0 4px;line-height:1.35">{{ $p->title }}</h3>
            <p style="font-size:.85rem;opacity:.6;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ \Illuminate\Support\Str::limit(strip_tags($p->excerpt), 90) }}</p>
            <span style="font-size:.75rem;opacity:.45">Started by {{ $p->public_author }} · {{ optional($p->published_at)->diffForHumans() }}</span>
          </div>
          <div style="flex:0 0 auto;text-align:center">
            <div style="font-size:1.35rem;font-weight:800;color:{{ $category->color }}">{{ $replies }}</div>
            <div style="font-size:.66rem;opacity:.5;text-transform:uppercase;letter-spacing:.06em">{{ \Illuminate\Support\Str::plural('reply', $replies) }}</div>
            @if($replies > 0 && $last)
              <div style="font-size:.66rem;opacity:.4;margin-top:4px">{{ $last->diffForHumans(short: true) }}</div>
            @endif
          </div>
        </a>
      @empty
        <p style="color:var(--text-dim)">No discussion topics yet — check back soon.</p>
      @endforelse
    </div>

    @include('partials.ad', ['placement' => 'category_list'])

    <div style="margin-top:32px">
      {{ $posts->links() }}
    </div>
  </main>
@endsection
