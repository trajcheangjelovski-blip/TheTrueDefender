@extends('layouts.app')
@section('title', 'Unsubscribed — TheTrueDefender')

@section('content')
  <main class="page-main" style="text-align:center">
    <h1>You're unsubscribed</h1>
    <p class="page-sub">{{ $email }} has been removed from our newsletter. You won't receive further emails.</p>
    <p style="margin-top:20px">Changed your mind? You can re-subscribe anytime from the <a href="{{ route('home') }}" style="color:var(--accent-2)">homepage</a>.</p>
  </main>
@endsection
