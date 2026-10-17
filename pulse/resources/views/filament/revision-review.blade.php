@php
  $fc = (array) $rev->fact_check;
  $changes = (array) $rev->change_log;
  $orig = (array) $rev->snapshot;
@endphp
<div style="display:grid;gap:18px;font-size:14px;line-height:1.55">
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <span><strong>Status:</strong> {{ $rev->status }}</span>
    <span><strong>Fact-check:</strong> {{ $fc['verdict'] ?? '—' }} · {{ $fc['claims_checked'] ?? 0 }} claims checked · {{ $fc['critical'] ?? 0 }} critical</span>
    <span><strong>Type:</strong> {{ $rev->story_type ?? '—' }}</span>
    <span><strong>Policy:</strong> v{{ $rev->policy_version }}</span>
    @if(!empty($rev->ai_usage['calls']))
      <span><strong>AI:</strong> {{ $rev->ai_usage['calls'] }} calls · {{ number_format(($rev->ai_usage['prompt_tokens'] ?? 0) + ($rev->ai_usage['completion_tokens'] ?? 0)) }} tokens
        @if(!is_null($rev->ai_usage['estimated_usd'] ?? null)) · ≈ ${{ $rev->ai_usage['estimated_usd'] }} @endif</span>
    @endif
  </div>

  @if(!empty($changes['summary']))
    <div><strong>What changed:</strong> {{ $changes['summary'] }}</div>
  @endif

  @if(!empty($changes['corrections']))
    <div style="border-left:3px solid #e33b4e;padding-left:10px">
      <strong>Factual corrections</strong> (shown to readers as a dated correction note):
      <ul style="margin:6px 0 0 18px;list-style:disc">@foreach($changes['corrections'] as $c)<li>{{ $c }}</li>@endforeach</ul>
    </div>
  @endif

  @if(!empty($fc['issues']))
    <div>
      <strong>Fact-check findings on this revision</strong>
      <ul style="margin:6px 0 0 18px;list-style:disc">
        @foreach($fc['issues'] as $i)
          <li><em>[{{ $i['severity'] }}]</em> “{{ $i['claim'] }}” — {{ $i['problem'] }} @if(!empty($i['fix'])) <span style="opacity:.8">(fix: {{ $i['fix'] }})</span>@endif</li>
        @endforeach
      </ul>
    </div>
  @endif
  @if(!empty($fc['source_conflicts']))
    <div><strong>Where sources disagree:</strong>
      <ul style="margin:6px 0 0 18px;list-style:disc">@foreach($fc['source_conflicts'] as $c)<li>{{ $c }}</li>@endforeach</ul>
    </div>
  @endif
  @if(!empty($changes['original_issues']))
    <details><summary><strong>Problems found in the published version ({{ count($changes['original_issues']) }})</strong></summary>
      <ul style="margin:6px 0 0 18px;list-style:disc">
        @foreach($changes['original_issues'] as $i)<li><em>[{{ $i['severity'] }}]</em> “{{ $i['claim'] }}” — {{ $i['problem'] }}</li>@endforeach
      </ul>
    </details>
  @endif

  @if(!empty($rev->sources))
    <div><strong>Sources</strong>
      <ol style="margin:6px 0 0 18px;list-style:decimal">
        @foreach($rev->sources as $s)
          <li><a href="{{ $s['url'] ?? '#' }}" target="_blank" rel="noopener" style="color:#3b82f6">{{ $s['name'] ?? 'source' }}@if(!empty($s['title'])) — {{ $s['title'] }}@endif</a>
            @if(!empty($s['published_at'])) <span style="opacity:.7">({{ \Illuminate\Support\Carbon::parse($s['published_at'])->format('M j, Y') }})</span>@endif</li>
        @endforeach
      </ol>
    </div>
  @endif

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div>
      <h3 style="font-weight:700;margin-bottom:6px">Revised</h3>
      <div style="font-weight:700">{{ $rev->title }}</div>
      <div style="opacity:.8;margin-bottom:8px">{{ $rev->excerpt }}</div>
      @if(!empty($rev->takeaways))<ul style="margin:0 0 8px 18px;list-style:disc">@foreach($rev->takeaways as $t)<li>{{ $t }}</li>@endforeach</ul>@endif
      <div class="prose">{!! $rev->body !!}</div>
    </div>
    <div style="opacity:.75">
      <h3 style="font-weight:700;margin-bottom:6px">Currently published</h3>
      <div style="font-weight:700">{{ $orig['title'] ?? '' }}</div>
      <div style="margin-bottom:8px">{{ $orig['excerpt'] ?? '' }}</div>
      <div class="prose">{!! $orig['body'] ?? '' !!}</div>
    </div>
  </div>
</div>
