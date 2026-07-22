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
    <p>Our reporting is prepared and reviewed by the TheTrueDefender editorial team. We build our coverage from established news sources and official statements, rewrite each story in our own words, and check it for accuracy and neutrality before publishing. Every article links to its primary source, and opinion pieces are clearly labeled. Read the full process on our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> page.</p>

    <h2>Accuracy &amp; Corrections</h2>
    <p>We correct mistakes promptly and transparently. If you spot an error, see our <a href="{{ route('page', 'corrections') }}" style="color:var(--accent-2)">Corrections policy</a> or email <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a>.</p>

    <h2>Support Independent News</h2>
    <p>We are powered by readers like you. Subscribing to our newsletter and shopping in our <a href="{{ route('shop.index') }}" style="color:#e0b04b">Patriot Shop</a> helps fund our newsroom. Our editorial decisions are independent of advertisers and shop sales.</p>

    <h2>Get in Touch</h2>
    <ul>
      <li>News &amp; tips: <a href="mailto:news@thetruedefender.news" style="color:var(--accent-2)">news@thetruedefender.news</a></li>
      <li>Corrections: <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a></li>
      <li>Support: <a href="mailto:support@thetruedefender.news" style="color:var(--accent-2)">support@thetruedefender.news</a></li>
    </ul>
    <p>Or use our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a> — we read every message.</p>
  </main>
@endsection
