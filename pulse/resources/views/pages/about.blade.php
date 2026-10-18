@extends('layouts.app')
@section('title', 'About Us — TheTrueDefender')

@section('content')
  <main class="page-main">
    <h1>About TheTrueDefender</h1>
    <p class="page-sub">Independent journalism. Unfiltered news. Delivered with integrity.</p>

    <h2>Who We Are</h2>
    <p>TheTrueDefender is an independent news organization founded in 2026 with a simple mission: report the news the way it happened, without spin, without an agenda, and without fear.</p>
    <p>We cover the stories that matter to everyday Americans — from the halls of Congress to communities across the heartland and events shaping the world — and we never forget that behind every headline are real people.</p>

    <h2>What We Believe</h2>
    <ul>
      <li><strong>Truth first.</strong> Facts before narratives, always.</li>
      <li><strong>Independence.</strong> We answer to our readers, not to corporations or political parties.</li>
      <li><strong>Hope matters.</strong> Our Story of Hope section exists because good news is real news too.</li>
      <li><strong>Free expression.</strong> Our Opinion pages welcome honest, well-argued perspectives.</li>
    </ul>

    <h2>How We Work</h2>
    @php
      $authors = \App\Models\User::where('is_author', true)->whereNotNull('slug')->orderBy('id')->get();
      $bylineList = $authors->map(fn ($a) =>
          '<a href="' . e(route('author.show', $a->slug)) . '" style="color:var(--accent-2)">' . e($a->name) . '</a>'
          . ($a->title ? ' (' . e($a->title) . ')' : '')
      )->implode(' and ');
      $bylineClause = $bylineList ? ' — ' . $bylineList . ' —' : '';
    @endphp
    <p>Our coverage is organized by beat{!! $bylineClause !!} and every article's byline links to the page for its beat. For each story we draw on <strong>several independent sources</strong> and official statements, cross-check what they report, and add our own context and significance — we don't simply republish another outlet's article. A story goes live only once it clears our publishing bar: at least three independent sources and a complete, substantial article. Opinion pieces are clearly labeled. Read the full process on our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> page.</p>

    <h2>Accuracy &amp; Corrections</h2>
    <p>We correct mistakes promptly and transparently. If you spot an error, see our <a href="{{ route('page', 'corrections') }}" style="color:var(--accent-2)">Corrections policy</a> or send it through our <a href="{{ route('page', 'contact') }}#contact-form" style="color:var(--accent-2)">contact form</a>.</p>

    <h2>Support Independent News</h2>
    <p>We are powered by readers like you. Subscribing to our newsletter helps fund our newsroom. Our editorial decisions are independent of advertisers.</p>

    <h2>Get in Touch</h2>
    <ul>
      <li>Use our <a href="{{ route('page', 'contact') }}#contact-form" style="color:var(--accent-2)">contact form</a> for news tips, corrections, support and privacy requests.</li>
    </ul>
    <p>Or use our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a> — we read every message.</p>
  </main>
@endsection
