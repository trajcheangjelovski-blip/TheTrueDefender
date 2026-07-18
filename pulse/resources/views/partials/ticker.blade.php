<div class="ticker-bar">
  <span class="ticker-label">⚡ {{ ($hasBreaking ?? false) ? 'BREAKING' : 'LATEST' }}</span>
  <div class="ticker-track">
    <div class="ticker-content" id="tickerContent">
      @forelse($tickerPosts as $t)
        <a href="{{ route('post.show', $t->slug) }}" style="color:inherit;text-decoration:none">@if($t->is_breaking)<strong style="color:#ff5064">•</strong> @endif{{ $t->title }}</a>
      @empty
        <span>Welcome to TheTrueDefender — independent news, unfiltered.</span>
      @endforelse
    </div>
  </div>
</div>
