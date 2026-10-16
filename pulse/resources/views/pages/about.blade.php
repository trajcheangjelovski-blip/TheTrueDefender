@extends('layouts.app')
@section('title', 'About Us — TheTrueDefender')
@section('content')
  <main class="page-main">
    <h1>About TheTrueDefender</h1>
    <p class="page-sub">News, context, and commentary for readers following American affairs.</p>
    <h2>What we publish</h2>
    <p>TheTrueDefender covers US news, politics and world events. News, Analysis and Opinion labels describe the purpose of each article. A label is not a guarantee of independent verification.</p>
    <h2>How content is produced</h2>
    <p>The publishing system uses AI to help select stories, draft text, and prepare updates. It supports automated publication as well as manual editing. We do not claim that every article has been independently reported, fact-checked or approved by a human. Source links and attribution notes identify the material recorded for each article.</p>
    <p>Reporting from other publications must be credited to those publications. Access to a secondary report does not mean we have independently reviewed its underlying documents or interviewed its sources.</p>
    @include('partials.publisher')
    <h2>People and bylines</h2>
    <p>See <a href="{{ route('page', 'newsroom') }}">Our Newsroom</a> for contributor profiles. Biographical details are displayed only after confirmation; pen names are disclosed only when their status is confirmed.</p>
    <h2>Corrections and contact</h2>
    <p>Use our <a href="{{ route('page', 'contact') }}">contact page</a> to report an error, supplying the article URL and supporting evidence. Read our <a href="{{ route('page', 'corrections') }}">corrections policy</a> and <a href="{{ route('page', 'editorial-standards') }}">editorial standards</a>.</p>
  </main>
@endsection
