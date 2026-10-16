@extends('layouts.app')
@section('title', 'Editorial Standards — TheTrueDefender')
@section('meta_description', 'Standards for attribution, news and opinion, AI assistance, images, and corrections at TheTrueDefender.')
@section('content')
  <main class="page-main">
    <h1>Editorial Standards</h1>
    <p class="page-sub">The standards editors should apply, and the limitations of our workflow.</p>
    <h2>Sources and factual claims</h2>
    <p>Articles should identify the publisher, title and URL of material used. When a document is described by another publication, credit that publication rather than implying we independently obtained it. Never invent quotations, interviews, statistics or outreach. Unsupported claims belong in a private editorial review draft until verified.</p>
    <p>Multiple links do not necessarily represent independent sources. Syndicated copies and reports relying on the same document should not be treated as independent corroboration. Source counts, word counts and automated quality scores are internal tools, not Google approval requirements or evidence of factual accuracy.</p>
    <h2>News, Analysis and Opinion</h2>
    <p>News reports supported facts and attributed allegations. Analysis interprets evidence and explains limits. Opinion argues a viewpoint. News drafts should not end with an unlabeled editorial conclusion or an automatic “Our Take.” Substantial rewrites and separate commentary should be reviewed as drafts.</p>
    <h2>AI assistance and review</h2>
    <p>The system can use AI for drafting, research assistance, images and updates. It also contains automatic publishing paths. We do not represent every article as human fact-checked or approved. Automated checks cannot establish that a document was read, that a claim is true, or that an image may lawfully be reused.</p>
    <h2>Images and sensitive coverage</h2>
    <p>Captions should identify the subject, credit and rights source. Composites, illustrations and generated visuals must be labeled; generated scenes must not be presented as documentary photographs. Unknown provenance or rights require editorial review. Crime reporting should distinguish allegations from findings and avoid graphic details that do not help readers understand the event.</p>
    <h2>Bylines and accountability</h2>
    <p>Do not infer credentials, residence, employment history or identity from a display name. Confirm profile details before displaying them. Disclose confirmed pen names accurately.</p>
    @include('partials.publisher')
    <h2>Corrections</h2>
    <p>Report concerns through our <a href="{{ route('page', 'contact') }}">contact page</a>, with the article URL and evidence. See our <a href="{{ route('page', 'corrections') }}">corrections policy</a>.</p>
  </main>
@endsection
