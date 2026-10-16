<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostRedirect;
use App\Models\StatDaily;
use App\Support\BotDetector;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function show(Request $request, Post $post)
    {
        // A retired article (e.g. a consolidated duplicate) keeps its row but is
        // unpublished; send its URL to the surviving article with a 301 instead
        // of 404-ing, so links/search authority carry over.
        if ($post->status !== 'published' || ! $post->published_at || $post->published_at->isFuture()) {
            if ($to = PostRedirect::resolve($post->slug)) {
                return redirect('/post/' . $to, 301);
            }
            // Deliberately retired in the volume cut (archived, no replacement):
            // return 410 Gone so search engines drop it permanently and fast.
            // Drafts / pending items stay 404 (not yet / never meant to be public).
            abort($post->status === 'archived' ? 410 : 404);
        }

        // Crawlers, previewers and monitors still get the page (for SEO), but
        // they don't inflate the view counters.
        if (\App\Support\PrivacyConsent::allows($request, 'analytics') && ! BotDetector::isBot($request)) {
            $post->increment('views');
            StatDaily::bump('views');
        }

        $post->loadMissing(['category', 'author']);

        // Load topics with their published-story counts so we can (a) render the
        // chips and (b) pick the most substantial one for a "Full coverage" link.
        $post->load(['tags' => fn ($q) => $q->where('is_active', true)->withCount(['posts as stories_count' => fn ($qq) => $qq
            ->where('status', 'published')->where('published_at', '<=', now())])]);

        // The story's main topic hub: the tag with the most coverage, only if it
        // clears the substantial-hub threshold (so we never link a thin dead page).
        $primaryTopic = $post->tags
            ->sortByDesc('stories_count')
            ->first(fn ($t) => $t->stories_count >= \App\Models\Tag::MIN_STORIES);

        $selected = Post::published()->whereKeyNot($post->id)
            ->whereIn('id', $post->related_post_ids ?? [])->with(['category', 'author'])->get();
        $upNext = $selected->first();

        if ($post->allow_comments) {
            $post->load('approvedComments.approvedReplies');
        }

        $related = $selected->skip(1)->take(3);

        return view('posts.show', compact('post', 'related', 'primaryTopic', 'upNext'));
    }
}
