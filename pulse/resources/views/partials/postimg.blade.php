{{-- Post image background: real image if present, else emoji-on-gradient.
     Vars: $post, $class (bg element class), $grad (inline background style),
           $size (hero 1600x900 | card 800x450 | thumb 400x225),
           $eager (bool: eager-load + high priority — use for the LCP image only). --}}
@php
  $sz = $size ?? 'card';
  $eager = $eager ?? false;
  $srcset = $post->imageSrcset();
  $sizes = match ($sz) {
    'hero' => '100vw',
    'thumb' => '(max-width: 768px) 50vw, 400px',
    default => '(max-width: 768px) 100vw, 800px',
  };
@endphp
<div class="{{ $class }} img-ph" style="{{ $grad }}">
  @if($url = $post->imageUrl($sz))
    <img src="{{ $url }}" alt="{{ $post->title }}"
         @if($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
         loading="{{ $eager ? 'eager' : 'lazy' }}" @if($eager) fetchpriority="high" @endif decoding="async"
         style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" />
    <span class="img-logo">
      <span class="img-logo-mark">TTD</span>
      @if($sz !== 'thumb')<span class="img-logo-text">The True <em>Defender</em></span>@endif
    </span>
  @else
    <span class="ph-icon">{{ $post->image_icon }}</span>
  @endif
</div>
