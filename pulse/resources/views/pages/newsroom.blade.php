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
      <p>Our coverage is organized by beat, and every article must meet our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> before publication. Each article's byline links to the page for its beat.</p>
      <ul>
        @foreach($authors as $a)
          <li><a href="{{ route('author.show', $a->slug) }}" style="color:var(--accent-2)"><strong>{{ $a->name }}</strong></a>@if($a->title) — {{ $a->title }}@endif</li>
        @endforeach
      </ul>
    @else
      <p>Our coverage is produced by our editorial team, accountable for its accuracy, tone and fairness. Every article must meet our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> before it is published.</p>
    @endif

    <h2>What we cover</h2>
    <ul>
      <li><strong>Politics</strong> — Congress, the White House, elections, and policy.</li>
      <li><strong>US News</strong> — crime, business, health, weather, and communities across America.</li>
      <li><strong>World</strong> — the international stories that matter to Americans.</li>
      <li><strong>Story of Hope</strong> — uplifting human-interest stories.</li>
      <li><strong>Opinion</strong> — reader debate and independent commentary, always clearly labeled.</li>
    </ul>

    <h2>How we work</h2>
    <p>For each story we draw on <strong>several independent, reputable sources</strong> and official statements, reconcile what they report, and add our own context, significance and — where labeled — analysis. We do not republish or spin a single outlet's article. A story goes live only once it clears our publishing bar — several independent sources and a complete, substantial article — and we deliberately run fewer, more substantial pieces rather than a high volume of thin ones. We separate news from opinion, label commercial content, and keep editorial decisions independent of advertisers and our shop. Full details are on our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> page.</p>

    <h2>Corrections</h2>
    <p>We fix mistakes quickly and openly. See our <a href="{{ route('page', 'corrections') }}" style="color:var(--accent-2)">Corrections policy</a>, or send it through our <a href="{{ route('page', 'contact') }}#contact-form" style="color:var(--accent-2)">contact form</a>.</p>

    <h2>Contact</h2>
    <ul>
      <li>Use our <a href="{{ route('page', 'contact') }}#contact-form" style="color:var(--accent-2)">contact form</a> for news tips, corrections, support and privacy requests.</li>
    </ul>
    <p>Or reach us through our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a>.</p>
  </main>
@endsection
