@if ($paginator->hasPages())
    @php
        $base = 'display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;padding:0 12px;border-radius:9px;font-weight:700;font-size:.9rem;text-decoration:none;border:1px solid rgba(255,255,255,.12);';
        $link = $base . 'background:rgba(255,255,255,.05);color:#e8eaf2;';
        $active = $base . 'background:var(--accent,#e33b4e);border-color:var(--accent,#e33b4e);color:#fff;';
        $disabled = $base . 'background:rgba(255,255,255,.03);color:rgba(255,255,255,.25);cursor:default;';
    @endphp
    <nav aria-label="Pagination" style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin:36px 0 8px">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span style="{{ $disabled }}" aria-disabled="true">‹ Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" style="{{ $link }}">‹ Prev</a>
        @endif

        {{-- Page numbers --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="{{ $disabled }}">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span style="{{ $active }}" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" style="{{ $link }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" style="{{ $link }}">Next ›</a>
        @else
            <span style="{{ $disabled }}" aria-disabled="true">Next ›</span>
        @endif
    </nav>
@endif
