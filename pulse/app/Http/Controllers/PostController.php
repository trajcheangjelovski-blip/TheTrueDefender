<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostRedirect;
use App\Models\StatDaily;

class PostController extends Controller
{
    public function show(Post $post)
    {
        // A retired article (e.g. a consolidated duplicate) keeps its row but is
        // unpublished; send its URL to the surviving article with a 301 instead
        // of 404-ing, so links/search authority carry over.
        if ($post->status !== 'published') {
            if ($to = PostRedirect::resolve($post->slug)) {
                return redirect('/post/' . $to, 301);
            }
            abort(404);
        }

        $post->increment('views');
        StatDaily::bump('views');
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

        // "Up Next" — the single best next read to beat the one-article bounce.
        // Priority: shares a topic → same category → most-read overall. Never the
        // current post. Clicking it is auto-tracked (feeds the hook-CTR learning).
        $tagIds = $post->tags->pluck('id');
        $upNext = null;
        if ($tagIds->isNotEmpty()) {
            $upNext = Post::published()->whereKeyNot($post->id)->with('category')
                ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds))
                ->orderByDesc('is_featured')->latest('published_at')
                ->first();
        }
        $upNext ??= Post::published()->whereKeyNot($post->id)->with('category')
            ->where('category_id', $post->category_id)
            ->latest('published_at')->first();
        $upNext ??= Post::published()->whereKeyNot($post->id)->with('category')
            ->orderByDesc('views')->first();

        if ($post->allow_comments) {
            $post->load('approvedComments.approvedReplies');
        }

        $related = Post::published()
            ->where('category_id', $post->category_id)
            ->whereKeyNot($post->id)
            ->when($upNext, fn ($q) => $q->whereKeyNot($upNext->id)) // don't duplicate Up Next
            ->latest('published_at')
            ->take(3)->get();

        return view('posts.show', compact('post', 'related', 'primaryTopic', 'upNext'));
    }
}
