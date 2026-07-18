<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        $shopProducts = Product::active()->orderBy('sort_order')->take(8)->get();

        $featured = Post::published()->where('is_featured', true)
            ->with(['category', 'author'])
            ->latest('published_at')
            ->take(5)->get();

        if ($featured->isEmpty()) {
            $featured = Post::published()->with(['category', 'author'])
                ->latest('published_at')->take(5)->get();
        }

        // Trending: editor/AI-pinned stories first, then fill by view count.
        $pinned = Post::published()->trendingActive()->with('category')
            ->latest('published_at')->take(5)->get();

        $trending = $pinned->count() >= 5
            ? $pinned
            : $pinned->concat(
                Post::published()->with('category')
                    ->whereNotIn('id', $pinned->pluck('id'))
                    ->orderByDesc('views')->take(5 - $pinned->count())->get()
            );

        $sections = Category::where('is_active', true)
            ->orderBy('sort_order')->get()
            ->map(function (Category $cat) {
                $take = $cat->layout === 'feature' ? 4 : 3;
                return [
                    'cat' => $cat,
                    'posts' => $cat->posts()->published()->with('author')
                        ->latest('published_at')->take($take)->get(),
                ];
            })
            ->filter(fn ($s) => $s['posts']->isNotEmpty())
            ->values();

        return view('home', compact('featured', 'trending', 'sections', 'shopProducts'));
    }
}
