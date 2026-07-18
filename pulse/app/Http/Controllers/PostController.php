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

        if ($post->allow_comments) {
            $post->load('approvedComments.approvedReplies');
        }

        $related = Post::published()
            ->where('category_id', $post->category_id)
            ->whereKeyNot($post->id)
            ->latest('published_at')
            ->take(3)->get();

        return view('posts.show', compact('post', 'related'));
    }
}
