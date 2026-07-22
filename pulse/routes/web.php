<?php

use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Shop
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/{product:slug}', [ShopController::class, 'show'])->name('product.show');

Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');
Route::get('/checkout', [CartController::class, 'checkout'])->name('checkout');
Route::post('/checkout', [CartController::class, 'place'])->name('checkout.place');
Route::get('/checkout/success/{order:order_number}', [CartController::class, 'success'])->name('checkout.success');
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
Route::get('/order/{order:order_number}', [CartController::class, 'confirmation'])->name('order.confirmation');

// Audience (subscribers + web push)
Route::post('/subscribe', [SubscriberController::class, 'store'])->name('subscribe');
Route::get('/push/key', [PushController::class, 'key'])->name('push.key');
Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');

Route::get('/category/{category:slug}', [CategoryController::class, 'show'])->name('category.show');
Route::get('/post/{post:slug}', [PostController::class, 'show'])->name('post.show');
Route::post('/post/{post:slug}/comment', [CommentController::class, 'store'])
    ->middleware('throttle:5,1')->name('comment.store');

// Affiliate program
Route::prefix('affiliate')->name('affiliate.')->group(function () {
    Route::get('/apply', [AffiliateController::class, 'apply'])->name('apply');
    Route::post('/apply', [AffiliateController::class, 'submitApplication'])->name('apply.submit');
    Route::get('/login', [AffiliateController::class, 'showLogin'])->name('login');
    Route::post('/login', [AffiliateController::class, 'login'])->name('login.submit');
    Route::post('/logout', [AffiliateController::class, 'logout'])->name('logout');
    Route::get('/', [AffiliateController::class, 'dashboard'])
        ->middleware('auth:affiliate')->name('dashboard');
});

Route::post('/contact', [PageController::class, 'submitContact'])->name('contact.submit');
Route::get('/{slug}', [PageController::class, 'show'])
    ->whereIn('slug', ['about', 'contact', 'privacy', 'terms', 'editorial-standards', 'corrections'])
    ->name('page');
