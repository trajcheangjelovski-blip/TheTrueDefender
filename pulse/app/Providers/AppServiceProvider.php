<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\PageSeo;
use App\Models\Post;
use App\Services\Cart;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The "admin" role bypasses every permission check (super admin).
        Gate::before(fn ($user) => $user->hasRole('admin') ? true : null);

        // Use our dark-themed pagination (default Tailwind view renders giant SVG arrows here).
        Paginator::defaultView('pagination.ttd');
        Paginator::defaultSimpleView('pagination.ttd');

        // Windows dev: point OpenSSL at its config so VAPID/JWT signing works.
        // (Not set on Linux/Hetzner, where OpenSSL finds its config by default.)
        if (($conf = env('OPENSSL_CONF')) && ! getenv('OPENSSL_CONF')) {
            putenv("OPENSSL_CONF={$conf}");
        }

        // Categories for the nav & footer on every page.
        View::composer(['partials.nav', 'partials.footer'], function ($view) {
            $view->with('navCategories', Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get());
        });

        // Cart item count for the nav badge.
        View::composer('partials.nav', function ($view) {
            $view->with('cartCount', app(Cart::class)->count());
        });

        // Breaking ticker: active breaking stories first, then fill with the
        // latest headlines so the bar is never empty.
        View::composer('partials.ticker', function ($view) {
            $breaking = Post::published()->breakingActive()
                ->latest('published_at')->take(6)
                ->get(['id', 'title', 'slug', 'is_breaking']);

            $fill = Post::published()
                ->whereNotIn('id', $breaking->pluck('id'))
                ->latest('published_at')->take(6 - $breaking->count())
                ->get(['id', 'title', 'slug', 'is_breaking']);

            $view->with('tickerPosts', $breaking->concat($fill));
            $view->with('hasBreaking', $breaking->isNotEmpty());
        });

        // Admin-managed SEO meta for the static pages (home/shop/about/…).
        // Posts & categories set their own meta via @section, so we skip them.
        View::composer('layouts.app', function ($view) {
            $key = match (Route::currentRouteName()) {
                'home' => 'home',
                'shop.index' => 'shop',
                'page' => Route::current()?->parameter('slug'),
                default => null,
            };

            $view->with('pageSeo', $key ? PageSeo::forKey($key) : null);
        });
    }
}
