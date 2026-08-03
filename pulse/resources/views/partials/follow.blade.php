{{-- "Follow us" CTA — inline, not floating. Reads the same social settings as
     the footer and shows only the channels with a real URL set (admin-editable).
     Placed at engaged moments (end of article, homepage), BELOW keep-reading +
     subscribe so it never competes with on-site retention or owned capture. --}}
@php
  $followSocials = [
    'social_truth' => ['label' => 'Truth Social', 'glyph' => 'T'],
    'social_x' => ['label' => 'X.com', 'glyph' => '𝕏'],
    'social_facebook' => ['label' => 'Facebook', 'glyph' => 'f'],
    'social_youtube' => ['label' => 'YouTube', 'glyph' => '▶'],
    'social_telegram' => ['label' => 'Telegram', 'glyph' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>'],
  ];
  $followList = collect($followSocials)
    ->map(fn ($s, $key) => $s + ['url' => \App\Models\Setting::get($key), 'key' => $key])
    ->filter(fn ($s) => filled($s['url']) && $s['url'] !== '#')
    ->values();
@endphp
@if($followList->isNotEmpty())
  <aside class="follow-cta" aria-label="Follow us on social media">
    <div class="follow-cta-text">
      <span class="follow-brand" aria-label="The True Defender">
        <span class="logo-mark follow-logo-mark">TTD</span>
        <span class="logo-text">The True <em>Defender</em></span>
      </span>
      <span class="follow-cta-sub">Follow us for every story in your feed — never miss what we publish.</span>
    </div>
    <div class="follow-cta-btns">
      @foreach($followList as $s)
        <a href="{{ $s['url'] }}" target="_blank" rel="noopener noreferrer"
           class="follow-cta-btn follow-{{ $s['key'] }}" aria-label="Follow TheTrueDefender on {{ $s['label'] }}">
          {{ $s['label'] }}
        </a>
      @endforeach
    </div>
  </aside>
@endif
