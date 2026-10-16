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

    <h2>Who writes and reviews our reporting</h2>
    <p>TheTrueDefender is an independent news publication. Our articles are written by our named journalists{!! $bylineClause !!} and every story is reviewed against the standards below before it is published. Each news article carries a byline linking to the journalist who wrote it, and our editors are accountable for its accuracy and fairness.</p>

    <h2>How a story is reported</h2>
    <ul>
      <li><strong>Multiple sources.</strong> We do not republish or spin a single outlet's article. Each news story is built by comparing <em>several independent, reputable sources</em> and official statements, and reconciling what they report. Every source we relied on is listed in a <em>Sources</em> section on the article.</li>
      <li><strong>Original value added.</strong> We go beyond the facts of any one report: we add background and context, explain why a story matters to American readers, and — where appropriate and clearly labeled — our own analysis. We publish only facts supported by the underlying reporting and never invent quotes, figures or details.</li>
      <li><strong>Human editing.</strong> We use software tools, including AI, to help gather and draft coverage — the same way modern newsrooms do. But <strong>no article is published automatically</strong>: every story is edited, fact-checked and approved by a human editor against these standards before it goes live. A draft that cannot clear the bar is held back, not published.</li>
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
