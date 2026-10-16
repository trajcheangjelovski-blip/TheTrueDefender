@if(count($post->public_sources))
  <details class="article-sources">
    <summary>Sources &amp; attribution ({{ count($post->public_sources) }})</summary>
    <ul>
      @foreach($post->public_sources as $source)
        <li><a href="{{ $source['url'] }}" rel="noopener noreferrer">{{ $source['title'] }}</a>
          @if($source['publisher'])<span> — {{ $source['publisher'] }}</span>@endif
          @if($source['note'])<p>{{ $source['note'] }}</p>@endif
        </li>
      @endforeach
    </ul>
  </details>
@endif
