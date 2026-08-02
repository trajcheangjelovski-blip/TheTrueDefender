<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;

class TopicController extends Controller
{
    /** Directory of every topic that has at least one published story. */
    public function index()
    {
        $publishedScope = fn ($q) => $q->where('status', 'published')->where('published_at', '<=', now());

        // Only list topics substantial enough to be worth a page (≥ MIN_STORIES),
        // so the directory never shows near-empty hubs.
        $tags = Tag::where('is_active', true)
            ->whereHas('posts', $publishedScope, '>=', Tag::MIN_STORIES)
            ->withCount(['posts as stories_count' => $publishedScope])
            ->orderByDesc('stories_count')
            ->orderBy('name')
            ->get();

        return view('topics.index', compact('tags'));
    }

    /** A single topic hub: all published stories tagged with it. */
    public function show(Tag $tag)
    {
        abort_unless($tag->is_active, 404);

        $publishedScope = fn ($q) => $q->where('status', 'published')->where('published_at', '<=', now());

        $posts = $tag->publishedPosts()
            ->with('category', 'author')
            ->latest('published_at')
            ->paginate(12);

        // Thin hubs (below the threshold) still render — so article chips never
        // 404 — but get noindex so Google isn't fed near-empty pages.
        $indexable = $posts->total() >= Tag::MIN_STORIES;

        // Related topics: other substantial tags that co-occur on these stories.
        $related = Tag::where('tags.id', '!=', $tag->id)
            ->where('is_active', true)
            ->whereIn('tags.id', function ($q) use ($tag) {
                $q->select('pt2.tag_id')
                    ->from('post_tag as pt1')
                    ->join('post_tag as pt2', 'pt1.post_id', '=', 'pt2.post_id')
                    ->where('pt1.tag_id', $tag->id);
            })
            ->whereHas('posts', $publishedScope, '>=', Tag::MIN_STORIES)
            ->withCount(['posts as stories_count' => $publishedScope])
            ->orderByDesc('stories_count')
            ->limit(12)
            ->get();

        return view('topics.show', compact('tag', 'posts', 'related', 'indexable'));
    }
}
