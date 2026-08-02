<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController extends Controller
{
    public function show(Post $post)
    {
        abort_unless($post->status === 'published', 404);

        $post->increment('views');
        $post->loadMissing(['category', 'author']);

        // Load topics with their published-story counts so we can (a) render the
        // chips and (b) pick the most substantial one for a "Full coverage" link.
        $post->load(['tags' => fn ($q) => $q->withCount(['posts as stories_count' => fn ($qq) => $qq
            ->where('status', 'published')->where('published_at', '<=', now())])]);

        // The story's main topic hub: the tag with the most coverage, only if it
        // clears the substantial-hub threshold (so we never link a thin dead page).
        $primaryTopic = $post->tags
            ->sortByDesc('stories_count')
            ->first(fn ($t) => $t->stories_count >= \App\Models\Tag::MIN_STORIES);

        if ($post->allow_comments) {
            $post->load('approvedComments.approvedReplies');
        }

        $related = Post::published()
            ->where('category_id', $post->category_id)
            ->whereKeyNot($post->id)
            ->latest('published_at')
            ->take(3)->get();

        return view('posts.show', compact('post', 'related', 'primaryTopic'));
    }
}
