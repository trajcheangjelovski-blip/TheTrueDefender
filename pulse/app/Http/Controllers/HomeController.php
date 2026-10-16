<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Support\NewsQuiz;

class HomeController extends Controller
{
    public function index()
    {
        $shopProducts = config('shop.enabled')
            ? Product::active()->with('variants')->orderBy('sort_order')->take(4)->get() : collect();
        $featured = Post::published()->where('is_featured', true)->with(['category', 'author'])
            ->latest('published_at')->take(3)->get();
        if ($featured->isEmpty()) {
            $featured = Post::published()->with(['category', 'author'])->latest('published_at')->take(3)->get();
        }
        $used = $featured->pluck('id')->all();
        $sections = Category::where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (Category $cat) use (&$used) {
                $posts = $cat->posts()->published()->whereNotIn('id', $used)->with(['author', 'category'])
                    ->latest('published_at')->take($cat->layout === 'feature' ? 4 : 3)->get();
                $used = array_merge($used, $posts->pluck('id')->all());
                return ['cat' => $cat, 'posts' => $posts];
            })->filter(fn ($s) => $s['posts']->isNotEmpty())->values();
        // Editorial picks are labelled as such, never presented as measured popularity.
        $trending = Post::published()->trendingActive()->whereNotIn('id', $used)
            ->with(['category', 'author'])->latest('published_at')->take(5)->get();
        $used = array_merge($used, $trending->pluck('id')->all());
        $mostRead = Post::published()->whereNotIn('id', $used)->where('views', '>', 0)
            ->where('published_at', '>=', now()->subWeek())->with(['category', 'author'])
            ->orderByDesc('views')->latest('published_at')->take(5)->get();
        $used = array_merge($used, $mostRead->pluck('id')->all());
        $mostDiscussed = Post::published()->whereNotIn('id', $used)->with(['category', 'topApprovedComment'])
            ->withCount(['comments as comments_count' => fn ($q) => $q->where('status', 'approved')])
            ->whereHas('comments', fn ($q) => $q->where('status', 'approved'))->orderByDesc('comments_count')->take(5)->get();
        $used = array_merge($used, $mostDiscussed->pluck('id')->all());
        $brief = Post::published()->whereNotIn('id', $used)->where('published_at', '>=', now()->subDay())
            ->with(['category', 'author'])->latest('published_at')->take(5)->get();
        $poll = \App\Models\Poll::where('is_active', true)->with('options')->latest('id')->first();
        $quizData = NewsQuiz::current();
        $hasQuiz = $quizData !== null;
        $recentTimes = Post::published()->latest('published_at')->take(24)->pluck('published_at')
            ->filter()->map->getTimestamp()->values();
        return view('home', compact('featured', 'sections', 'trending', 'mostRead', 'mostDiscussed',
            'brief', 'poll', 'hasQuiz', 'quizData', 'recentTimes', 'shopProducts'));
    }
}
