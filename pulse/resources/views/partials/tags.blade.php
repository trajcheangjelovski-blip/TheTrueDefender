{{-- Topic chips for a post. Internal links into evergreen /topic hubs. --}}
@if(isset($post) && $post->relationLoaded('tags') ? $post->tags->isNotEmpty() : $post->tags()->exists())
  <div class="topic-tags" aria-label="Topics">
    <span class="topic-tags-label">Topics</span>
    @foreach($post->tags as $tag)
      <a href="{{ route('topic.show', $tag) }}" class="topic-chip" rel="tag">{{ $tag->name }}</a>
    @endforeach
  </div>
@endif
