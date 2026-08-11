<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        $shopProducts = Product::active()->with('variants')->orderBy('sort_order')->take(8)->get();

        $featured = Post::published()->where('is_featured', true)
            ->with(['category', 'author'])
            ->latest('published_at')
            ->take(5)->get();

        if ($featured->isEmpty()) {
            $featured = Post::published()->with(['category', 'author'])
                ->latest('published_at')->take(5)->get();
        }

        // Trending: editor/AI-pinned stories first, then fill by view count.
        // Opinion posts are forum discussion topics — excluded from Trending.
        $opinionId = Category::where('slug', 'opinion')->value('id');

        $pinned = Post::published()->trendingActive()->with('category')
            ->where('category_id', '!=', $opinionId)
            ->latest('published_at')->take(5)->get();

        $trending = $pinned->count() >= 5
            ? $pinned
            : $pinned->concat(
                Post::published()->with('category')
                    ->where('category_id', '!=', $opinionId)
                    ->whereNotIn('id', $pinned->pluck('id'))
                    ->orderByDesc('views')->take(5 - $pinned->count())->get()
            );

        // Most read this week — real engagement, drives return visits.
        $mostRead = Post::published()->with('category')
            ->where('published_at', '>=', now()->subWeek())
            ->when($opinionId, fn ($q) => $q->where('category_id', '!=', $opinionId))
            ->orderByDesc('views')->latest('published_at')
            ->take(5)->get();

        // Active reader poll (if any).
        $poll = \App\Models\Poll::where('is_active', true)->with('options')->latest('id')->first();

        // Is today's quiz available?
        $quizData = json_decode((string) \App\Models\Setting::get('daily_quiz'), true);
        $hasQuiz = ! empty($quizData['questions']);

        // Most discussed — active conversations pull readers back to the threads.
        $mostDiscussed = Post::published()->with(['category', 'topApprovedComment'])
            ->withCount(['comments as comments_count' => fn ($q) => $q->where('status', 'approved')])
            ->orderByDesc('comments_count')->latest('published_at')
            ->take(8)->get()
            ->filter(fn (Post $p) => $p->comments_count > 0)
            ->take(5)->values();

        // ☕ Morning Brief — the day's most important stories, auto-selected the
        // same way the email digest picks them (featured/breaking first, newest).
        // Uses real excerpts (no fabricated summaries); hides itself if empty.
        $brief = Post::published()->with('category')
            ->where('published_at', '>=', now()->subDay())
            ->when($opinionId, fn ($q) => $q->where('category_id', '!=', $opinionId))
            ->orderByDesc('is_featured')->orderByDesc('is_breaking')->latest('published_at')
            ->take(5)->get();

        // Publish times of recent stories → the client compares them to the
        // reader's last visit to show a subtle, honest "N new since last visit".
        $recentTimes = Post::published()->latest('published_at')->take(24)
            ->pluck('published_at')->filter()->map->getTimestamp()->values();

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

        return view('home', compact('featured', 'trending', 'sections', 'shopProducts', 'mostRead', 'poll', 'hasQuiz', 'mostDiscussed', 'brief', 'recentTimes'));
    }
}
