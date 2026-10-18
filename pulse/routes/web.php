<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// The former shop was removed; its old URLs are gone for good.
Route::any('/{shopPath}/{rest?}', fn () => abort(410))->where(['shopPath' => 'shop|cart|checkout|order|stripe', 'rest' => '.*']);

// Audience (subscribers + web push)
Route::post('/subscribe', [SubscriberController::class, 'store'])->name('subscribe');
Route::get('/push/key', [PushController::class, 'key'])->name('push.key');
Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
Route::post('/push/topics', [PushController::class, 'topics'])->name('push.topics');

Route::get('/topics', [\App\Http\Controllers\TopicController::class, 'index'])->name('topics.index');
Route::get('/topic/{tag:slug}', [\App\Http\Controllers\TopicController::class, 'show'])->name('topic.show');

// Named-author pages (E-E-A-T): bio + the author's published articles.
Route::get('/author/{user:slug}', [\App\Http\Controllers\AuthorController::class, 'show'])->name('author.show');

Route::get('/category/{category:slug}', [CategoryController::class, 'show'])->name('category.show');
Route::get('/post/{post:slug}', [PostController::class, 'show'])->name('post.show')
    ->missing(function (\Illuminate\Http\Request $request) {
        // Slug has no Post row at all (e.g. a deleted article). Honor a redirect
        // if one exists so retired URLs still 301 to their replacement, else 404.
        $slug = $request->route('post');
        if (is_string($slug) && ($to = \App\Models\PostRedirect::resolve($slug))) {
            return redirect('/post/' . $to, 301);
        }
        abort(404);
    });
Route::post('/post/{post:slug}/comment', [CommentController::class, 'store'])
    ->middleware('throttle:5,1')->name('comment.store');

// The former affiliate program was removed; its old URLs are gone for good.
Route::any('/affiliate/{any?}', fn () => abort(410))->where('any', '.*');

Route::post('/contact', [PageController::class, 'submitContact'])->name('contact.submit');
Route::post('/poll/vote', [\App\Http\Controllers\PollController::class, 'vote'])->name('poll.vote');
Route::get('/quiz', [\App\Http\Controllers\QuizController::class, 'show'])->name('quiz');
Route::get('/ads.txt', \App\Http\Controllers\AdsTxtController::class);
Route::get('/sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index']);
Route::get('/news-sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'news']);
Route::get('/rss', [\App\Http\Controllers\FeedController::class, 'rss'])->name('rss');
Route::get('/feed', [\App\Http\Controllers\FeedController::class, 'rss']);
Route::post('/track/click', [\App\Http\Controllers\TrackController::class, 'click'])->name('track.click');
Route::post('/track/impressions', [\App\Http\Controllers\TrackController::class, 'impressions'])->name('track.impressions');
Route::post('/track/heartbeat', [\App\Http\Controllers\TrackController::class, 'heartbeat'])
    ->middleware('throttle:30,1')->name('track.heartbeat');
Route::get('/newsletter/unsubscribe/{subscriber}', [PageController::class, 'unsubscribe'])
    ->name('newsletter.unsubscribe')->middleware('signed');
Route::get('/{slug}', [PageController::class, 'show'])
    ->whereIn('slug', ['about', 'contact', 'privacy', 'terms', 'editorial-standards', 'corrections', 'newsroom'])
    ->name('page');
