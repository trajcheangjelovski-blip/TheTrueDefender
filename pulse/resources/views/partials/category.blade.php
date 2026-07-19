@php
    $badge = fn() => 'background:' . $cat->color . ';color:#fff';
    $grad  = fn() => 'background: linear-gradient(135deg, ' . $cat->color . '33, #0b0910)';
@endphp

<section class="section reveal" id="{{ $cat->slug }}">
  <div class="section-head">
    <h2>
      <span class="head-icon" style="background:{{ $cat->color }}1f; border-color:{{ $cat->color }}55">{{ $cat->icon }}</span>
      {{ $cat->name }}
    </h2>
    <div class="head-line" style="background:linear-gradient(90deg, {{ $cat->color }}66, transparent)"></div>
    <a href="{{ route('category.show', $cat) }}" class="head-link" style="color:{{ $cat->color }}">View all →</a>
  </div>

  @switch($cat->layout)

    @case('feature')
      @php $main = $posts->first(); $rest = $posts->slice(1); @endphp
      <div class="feat-grid">
        <a href="{{ route('post.show', $main) }}" class="story-card tilt-card feat-main" data-tilt>
          @include('partials.postimg', ['post' => $main, 'class' => 'story-bg', 'grad' => $grad()])
          <div class="story-scrim"></div>
          <div class="card-glare"></div>
          <div class="story-content">
            <span class="badge" style="{{ $badge() }}">{{ strtoupper($cat->name) }}</span>
            <h3 class="feat-title">{{ $main->title }}</h3>
            <p>{{ $main->excerpt }}</p>
            <span class="story-author">By {{ $main->public_author }} · {{ $main->time_ago }}</span>
          </div>
        </a>
        <div class="feat-list">
          @foreach($rest as $p)
            <a href="{{ route('post.show', $p) }}" class="feat-item tilt-card" data-tilt>
              <div class="card-glare"></div>
              @include('partials.postimg', ['post' => $p, 'class' => 'feat-thumb', 'size' => 'thumb', 'grad' => $grad()])
              <div class="feat-body">
                <h3>{{ $p->title }}</h3>
                <span class="meta-time">By {{ $p->public_author }} · {{ $p->time_ago }}</span>
              </div>
            </a>
          @endforeach
        </div>
      </div>
      @break

    @case('overlay')
      <div class="overlay-grid">
        @foreach($posts as $p)
          <a href="{{ route('post.show', $p) }}" class="story-card tilt-card ov-card" data-tilt>
            @include('partials.postimg', ['post' => $p, 'class' => 'story-bg', 'grad' => $grad()])
            <div class="story-scrim"></div>
            <div class="card-glare"></div>
            <div class="story-content">
              <span class="badge" style="{{ $badge() }}">{{ strtoupper($cat->name) }}</span>
              <h3>{{ $p->title }}</h3>
              <span class="meta-time">By {{ $p->public_author }} · {{ $p->time_ago }}</span>
            </div>
          </a>
        @endforeach
      </div>
      @break

    @case('quotes')
      <div class="quote-grid">
        @foreach($posts as $p)
          <a href="{{ route('post.show', $p) }}" class="quote-card tilt-card" data-tilt>
            <div class="card-glare"></div>
            <span class="quote-mark" style="color:{{ $cat->color }}">“</span>
            <h3>{{ $p->title }}</h3>
            <p>{{ $p->excerpt }}</p>
            <div class="quote-author">
              <span class="avatar" style="background:linear-gradient(135deg, {{ $cat->color }}, #1a1030)">{{ $p->public_author_initials }}</span>
              <div><strong>{{ $p->public_author }}</strong><span>{{ $cat->name }} · {{ $p->time_ago }}</span></div>
            </div>
          </a>
        @endforeach
      </div>
      @break

    @case('briefs')
      <div class="briefs-grid">
        @foreach($posts as $p)
          <a href="{{ route('post.show', $p) }}" class="brief-card tilt-card" data-tilt>
            <span class="brief-bar" style="background:{{ $cat->color }}"></span>
            <div class="card-glare"></div>
            <span class="brief-icon">{{ $p->image_icon }}</span>
            <h3>{{ $p->title }}</h3>
            <p>{{ $p->excerpt }}</p>
            <div class="news-foot"><span>By {{ $p->public_author }}</span><span>{{ $p->time_ago }}</span></div>
          </a>
        @endforeach
      </div>
      @break

    @default
      <div class="rows-list">
        @foreach($posts as $p)
          <a href="{{ route('post.show', $p) }}" class="row-card tilt-card" data-tilt>
            <div class="card-glare"></div>
            @include('partials.postimg', ['post' => $p, 'class' => 'row-img', 'size' => 'thumb', 'grad' => $grad()])
            <div class="row-body">
              <span class="badge" style="{{ $badge() }}">{{ strtoupper($cat->name) }}</span>
              <h3>{{ $p->title }}</h3>
              <p>{{ $p->excerpt }}</p>
              <div class="news-foot"><span>By {{ $p->public_author }}</span><span>{{ $p->time_ago }}</span></div>
            </div>
          </a>
        @endforeach
      </div>
  @endswitch
</section>
