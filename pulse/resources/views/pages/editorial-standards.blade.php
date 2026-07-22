@extends('layouts.app')
@section('title', 'Editorial Standards — TheTrueDefender')
@section('meta_description', 'How TheTrueDefender sources, writes, reviews and corrects its reporting.')

@section('content')
  <main class="page-main">
    <h1>Editorial Standards</h1>
    <p class="page-sub">How our newsroom sources, writes, reviews and corrects the news.</p>

    <h2>Who prepares our reporting</h2>
    <p>TheTrueDefender is an independent news publication. Our reporting is prepared by the TheTrueDefender editorial team, which curates stories from established news wires and official sources, rewrites them in our own words, and reviews them before publication. Stories are published under our editorial-team byline rather than an individual's, and are held to the standards below.</p>

    <h2>How stories are produced</h2>
    <ul>
      <li><strong>Sourcing.</strong> We start from reputable, established reporting and official statements. Each article links to its primary source in a <em>Sources</em> section at the bottom.</li>
      <li><strong>Rewriting.</strong> We summarize and rewrite stories in original language. We do not copy source articles verbatim, and we only publish facts present in the underlying reporting — we do not invent quotes, figures, or details.</li>
      <li><strong>Review.</strong> Every story is checked for accuracy, tone, and neutrality before it goes live. Opinion pieces are clearly labeled and reflect the views of their authors.</li>
      <li><strong>Technology.</strong> We use automated tools to help draft and organize coverage, but every published story is reviewed against these standards by our editorial team.</li>
    </ul>

    <h2>Accuracy &amp; independence</h2>
    <p>We separate news from opinion, we label sponsored or commercial content, and our editorial decisions are not influenced by advertisers or the products in our shop. When facts are disputed or developing, we say so.</p>

    <h2>Corrections</h2>
    <p>We correct errors promptly and transparently. If you believe something we published is inaccurate, please see our <a href="{{ route('page', 'corrections') }}" style="color:var(--accent-2)">Corrections policy</a> or email <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a>.</p>

    <h2>Contact the newsroom</h2>
    <ul>
      <li>News tips &amp; editorial: <a href="mailto:news@thetruedefender.news" style="color:var(--accent-2)">news@thetruedefender.news</a></li>
      <li>Corrections: <a href="mailto:corrections@thetruedefender.news" style="color:var(--accent-2)">corrections@thetruedefender.news</a></li>
      <li>Support &amp; shop: <a href="mailto:support@thetruedefender.news" style="color:var(--accent-2)">support@thetruedefender.news</a></li>
    </ul>
  </main>
@endsection
