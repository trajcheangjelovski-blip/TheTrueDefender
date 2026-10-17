@extends('layouts.app')
@section('title', 'Editorial Standards — TheTrueDefender')
@section('meta_description', 'How TheTrueDefender sources, writes, edits and corrects its reporting — multiple sources per story, named journalists, and human editorial review before every article is published.')

@php
  $authors = \App\Models\User::where('is_author', true)->whereNotNull('slug')->orderBy('id')->get();
  $bylineList = $authors->map(fn ($a) =>
      '<a href="' . e(route('author.show', $a->slug)) . '" style="color:var(--accent-2)">' . e($a->name) . '</a>'
      . ($a->title ? ' (' . e($a->title) . ')' : '')
  )->implode(' and ');
  $bylineClause = $bylineList ? ' — ' . $bylineList . ' —' : '';
@endphp

@section('content')
  <main class="page-main">
    <h1>Editorial Standards</h1>
    <p class="page-sub">How our newsroom sources, writes, edits and corrects the news.</p>

    <h2>Who is accountable for our reporting</h2>
    <p>TheTrueDefender is an independent news publication. Our coverage is organized by beat{!! $bylineClause !!} and every story must meet the standards below before it is published. Each news article carries the byline of its beat, and the publication is accountable for its accuracy and fairness — we correct errors promptly.</p>

    <h2>How a story is reported</h2>
    <ul>
      <li><strong>Multiple sources.</strong> We do not republish or spin a single outlet's article. Each news story is built by comparing <em>several independent, reputable sources</em> and official statements, and reconciling what they report.</li>
      <li><strong>Original value added.</strong> We go beyond the facts of any one report: we add background and context, explain why a story matters to American readers, and — where appropriate and clearly labeled — our own analysis. We publish only facts supported by the underlying reporting and never invent quotes, figures or details.</li>
      <li><strong>A publishing bar.</strong> A story goes live only when it clears our bar: at least three independent sources, a complete and substantial article, and no claim the sources don't support. A draft that cannot clear the bar is held back, not published.</li>
      <li><strong>Fewer, better stories.</strong> We deliberately publish a smaller number of substantial, well-sourced articles rather than a high volume of thin rewrites.</li>
    </ul>

    <h2>News vs. opinion</h2>
    <p>We keep reporting separate from commentary. Opinion and analysis pieces are clearly labeled and reflect the views of their named authors. Sponsored or commercial content is labeled, and our editorial decisions are not influenced by advertisers or by the products in our shop.</p>

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
