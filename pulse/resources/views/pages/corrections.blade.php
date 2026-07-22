@extends('layouts.app')
@section('title', 'Corrections Policy — TheTrueDefender')
@section('meta_description', 'How to report an error and how TheTrueDefender handles corrections.')

@section('content')
  <main class="page-main">
    <h1>Corrections Policy</h1>
    <p class="page-sub">We fix mistakes quickly and openly.</p>

    <h2>Our commitment</h2>
    <p>Accuracy is the foundation of trust. When we get something wrong, we correct it as soon as we can and are transparent about what changed. Significant corrections are noted on the article itself.</p>

    <h2>How to report an error</h2>
    <p>If you spot a factual error — a wrong name, date, figure, or misquote — please tell us. Include the article title or link and a short description of what's wrong (and, if possible, a source for the correct information).</p>
    <ul>
      <li>Email: <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a></li>
      <li>Or use our <a href="{{ route('page', 'contact') }}" style="color:var(--accent-2)">Contact page</a> and choose the subject "Correction".</li>
    </ul>

    <h2>What happens next</h2>
    <ul>
      <li>We review every correction request, usually within one business day.</li>
      <li>If a correction is warranted, we update the article promptly.</li>
      <li>For material factual errors, we add a brief note indicating the article was corrected and when.</li>
    </ul>

    <p>You can also review how we produce and check our reporting on our <a href="{{ route('page', 'editorial-standards') }}" style="color:var(--accent-2)">Editorial Standards</a> page.</p>
  </main>
@endsection
