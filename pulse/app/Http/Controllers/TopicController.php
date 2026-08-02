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

        $tags = Tag::where('is_active', true)
            ->whereHas('posts', $publishedScope)
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

        $posts = $tag->publishedPosts()
            ->with('category', 'author')
            ->latest('published_at')
            ->paginate(12);

        // Related topics: other tags that co-occur on these stories.
        $related = Tag::where('tags.id', '!=', $tag->id)
            ->where('is_active', true)
            ->whereIn('tags.id', function ($q) use ($tag) {
                $q->select('pt2.tag_id')
                    ->from('post_tag as pt1')
                    ->join('post_tag as pt2', 'pt1.post_id', '=', 'pt2.post_id')
                    ->where('pt1.tag_id', $tag->id);
            })
            ->withCount(['posts as stories_count' => fn ($q) => $q->where('status', 'published')
                ->where('published_at', '<=', now())])
            ->orderByDesc('stories_count')
            ->limit(12)
            ->get();

        return view('topics.show', compact('tag', 'posts', 'related'));
    }
}
