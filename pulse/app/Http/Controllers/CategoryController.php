<?php

namespace App\Http\Controllers;

use App\Models\Category;

class CategoryController extends Controller
{
    public function show(Category $category)
    {
        abort_unless($category->is_active, 404);

        // The Opinion category is a discussion forum: topics + reply counts.
        if ($category->slug === 'opinion') {
            $posts = $category->posts()->published()
                ->with('author')
                ->withCount(['comments as replies_count' => fn ($q) => $q->where('status', 'approved')])
                ->withMax(['comments as last_reply_at' => fn ($q) => $q->where('status', 'approved')], 'created_at')
                ->orderByRaw('COALESCE(published_at, created_at) DESC')
                ->paginate(15);

            return view('categories.opinion', compact('category', 'posts'));
        }

        $posts = $category->posts()->published()
            ->with('author')
            ->latest('published_at')
            ->paginate(9);

        return view('categories.show', compact('category', 'posts'));
    }
}
