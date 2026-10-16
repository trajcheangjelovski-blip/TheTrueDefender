@extends('layouts.app')
@section('title', 'Our Newsroom — TheTrueDefender')
@section('meta_description', 'Contributor profiles, publishing process and editorial responsibility at TheTrueDefender.')
@section('content')
  <main class="page-main">
    <h1>Our Newsroom</h1>
    <p class="page-sub">Contributors and publishing responsibility.</p>
    @include('partials.publisher')
    <h2>Contributor profiles</h2>
    @php $authors = \App\Models\User::authors()->orderBy('id')->get(); @endphp
    <ul>
      @forelse($authors as $author)
        <li><a href="{{ route('author.show', $author->slug) }}">{{ $author->name }}</a>@if(! $author->hasConfirmedProfile()) — profile details awaiting confirmation@endif</li>
      @empty
        <li>No confirmed contributor profiles are currently listed.</li>
      @endforelse
    </ul>
    <h2>Publishing process</h2>
    <p>AI-assisted drafting and automated publishing tools are part of this site's workflow. The presence of a byline does not certify that its named contributor conducted interviews or reviewed original records. Sources should distinguish primary documents from another publication's reporting.</p>
    <p>Read the <a href="{{ route('page', 'editorial-standards') }}">editorial standards</a>. To raise a factual or attribution issue, use our <a href="{{ route('page', 'contact') }}">contact page</a>.</p>
  </main>
@endsection
