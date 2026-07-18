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

    <h2>Support Independent News</h2>
    <p>We are powered by readers like you. Subscribing to our newsletter and shopping in our <a href="{{ route('home') }}#shop" style="color:#e0b04b">Patriot Shop</a> directly funds our newsroom and keeps our reporting free for everyone.</p>

    <h2>Get in Touch</h2>
    <p>Have a tip, correction, or question? Visit our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a> — we read every message.</p>
  </main>
@endsection
