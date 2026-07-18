{{-- Ad placement, fully controlled from the admin (Ads → Placements).
     Usage: @include('partials.ad', ['placement' => 'article_mid']) --}}
@php
    $p = \App\Models\AdPlacement::byKey($placement ?? '');
    $client = \App\Models\Setting::get('adsense_client', config('services.adsense.client'));
@endphp

@if($p && $p->is_enabled)
  @if(filled($p->custom_html))
    {{-- Custom ad code (any network) --}}
    <div class="ad-slot"><span class="ad-label">Advertisement</span>{!! $p->custom_html !!}</div>
  @elseif(filled($client) && filled($p->ad_slot))
    {{-- Google AdSense --}}
    <div class="ad-slot">
      <span class="ad-label">Advertisement</span>
      @if($p->format === 'in-article')
        <ins class="adsbygoogle" style="display:block; text-align:center;"
             data-ad-layout="in-article" data-ad-format="fluid"
             data-ad-client="{{ $client }}" data-ad-slot="{{ $p->ad_slot }}"></ins>
      @else
        <ins class="adsbygoogle" style="display:block"
             data-ad-client="{{ $client }}" data-ad-slot="{{ $p->ad_slot }}"
             data-ad-format="auto" data-full-width-responsive="true"></ins>
      @endif
      <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
    </div>
  @elseif(config('app.debug'))
    <div class="ad-slot">
      <span class="ad-label">Advertisement</span>
      <div class="ad-placeholder">{{ $p->name }} ({{ $p->format }}) — enabled; add a slot ID or custom code in the admin</div>
    </div>
  @endif
@endif
