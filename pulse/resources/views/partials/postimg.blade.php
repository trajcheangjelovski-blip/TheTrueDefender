{{-- Post image background: real image if present, else emoji-on-gradient.
     Vars: $post, $class (bg element class), $grad (inline background style),
           $size (optional: hero 1600x900 | card 800x450 | thumb 400x225). --}}
<div class="{{ $class }} img-ph" style="{{ $grad }}">
  @if($url = $post->imageUrl($size ?? 'card'))
    <img src="{{ $url }}" alt="{{ $post->title }}" loading="lazy"
         style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
    <span class="img-logo">
      <span class="img-logo-mark">TTD</span>
      @if(($size ?? 'card') !== 'thumb')<span class="img-logo-text">The True <em>Defender</em></span>@endif
    </span>
  @else
    <span class="ph-icon">{{ $post->image_icon }}</span>
  @endif
</div>
