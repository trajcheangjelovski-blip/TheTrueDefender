@extends('layouts.app')
@section('title', 'Our Newsroom — TheTrueDefender')
@section('meta_description', 'The people and process behind TheTrueDefender — an independent American news publication.')

@section('content')
  <main class="page-main">
    <h1>Our Newsroom</h1>
    <p class="page-sub">Who we are, what we cover, and how we work.</p>

    <h2>About the publication</h2>
    <p>TheTrueDefender is an independent American news publication founded in 2026. We cover national politics, US news, world affairs, uplifting Story of Hope features, and reader opinion — reporting the news plainly and without an agenda.</p>

    <h2>Our journalists</h2>
    @php
      $authors = \App\Models\User::where('is_author', true)->whereNotNull('slug')->orderBy('id')->get();
    @endphp
    @if($authors->isNotEmpty())
      <p>Our articles are written by our named journalists and reviewed by our editors against our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> before publication. Every article carries a byline linking to the journalist who wrote it.</p>
      <ul>
        @foreach($authors as $a)
          <li><a href="{{ route('author.show', $a->slug) }}" style="color:var(--accent-2)"><strong>{{ $a->name }}</strong></a>@if($a->title) — {{ $a->title }}@endif</li>
        @endforeach
      </ul>
    @else
      <p>Our coverage is produced and reviewed by our editorial team, accountable for its accuracy, tone and fairness. Every article is reviewed against our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> before it is published.</p>
    @endif

    <h2>What we cover</h2>
    <ul>
      <li><strong>Politics</strong> — Congress, the White House, elections, and policy.</li>
      <li><strong>US News</strong> — crime, business, health, weather, and communities across America.</li>
      <li><strong>World</strong> — the international stories that matter to Americans.</li>
      <li><strong>Story of Hope</strong> — original uplifting human-interest features.</li>
      <li><strong>Opinion</strong> — reader debate and independent commentary, always clearly labeled.</li>
    </ul>

    <h2>How we work</h2>
    <p>For each story we draw on <strong>several independent, reputable sources</strong> and official statements, reconcile what they report, verify the facts, and add our own context, significance and — where labeled — analysis. We do not republish or spin a single outlet's article. We use modern tools, including AI, to help gather and draft coverage, but <strong>nothing publishes automatically</strong>: every story is edited and approved by a human editor first, and we deliberately run fewer, more substantial pieces rather than a high volume of thin ones. We separate news from opinion, label commercial content, and keep editorial decisions independent of advertisers and our shop. Full details are on our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> page.</p>

    <h2>Corrections</h2>
    <p>We fix mistakes quickly and openly. See our <a href="{{ route('page', 'corrections') }}" style="color:var(--accent-2)">Corrections policy</a>, or email <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a>.</p>

    <h2>Contact</h2>
    <ul>
      <li>Newsroom &amp; tips: <a href="mailto:news@thetruedefender.news" style="color:var(--accent-2)">news@thetruedefender.news</a></li>
      <li>Corrections: <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a></li>
      <li>Support: <a href="mailto:support@thetruedefender.news" style="color:var(--accent-2)">support@thetruedefender.news</a></li>
    </ul>
    <p>Or reach us through our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a>.</p>
  </main>
@endsection
