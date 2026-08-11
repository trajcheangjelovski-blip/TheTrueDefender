@php
  $total = $poll->totalVotes();
  $voted = request()->cookie('poll_voted_' . $poll->id);
@endphp
<div class="poll-card">
  <h3 class="poll-q">{{ $poll->question }}</h3>

  @unless($voted)
    <p class="poll-sub" style="margin:-4px 0 14px;opacity:.6;font-size:.85rem">Cast your vote below and see how other readers voted.</p>
  @endunless

  @if($voted)
    <ul class="poll-results">
      @foreach($poll->options as $opt)
        @php $pct = $total ? round($opt->votes * 100 / $total) : 0; @endphp
        <li>
          <div class="poll-bar-row"><span>{{ $opt->label }}</span><strong>{{ $pct }}%</strong></div>
          <div class="poll-bar"><div class="poll-bar-fill" style="width:{{ $pct }}%"></div></div>
        </li>
      @endforeach
    </ul>
    <p class="poll-total">{{ number_format($total) }} {{ Str::plural('vote', $total) }} · thanks for voting</p>
    {{-- Subtle next-step after a vote (high-intent) — not a popup. --}}
    <div class="poll-convert" data-hide-if-subscribed>
      <span>Want tomorrow's question in your inbox?</span>
      <a href="#" class="poll-convert-cta" data-open-subscribe>Get the Morning Brief →</a>
    </div>
  @else
    <form method="POST" action="{{ route('poll.vote') }}" class="poll-form">
      @csrf
      <input type="hidden" name="poll_id" value="{{ $poll->id }}" />
      @foreach($poll->options as $opt)
        <button type="submit" name="option_id" value="{{ $opt->id }}" class="poll-option">{{ $opt->label }}</button>
      @endforeach
    </form>
  @endif
</div>
