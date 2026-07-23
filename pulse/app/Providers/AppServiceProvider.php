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

        // Apply admin-configured SMTP settings at runtime (so email can be set up
        // from the panel without editing .env). Guarded — the table may not exist
        // yet during install/migrate.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')
                && filled($host = \App\Models\Setting::get('mail_host'))) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $host,
                    'mail.mailers.smtp.port' => (int) \App\Models\Setting::get('mail_port', 587),
                    'mail.mailers.smtp.username' => \App\Models\Setting::get('mail_username'),
                    'mail.mailers.smtp.password' => \App\Models\Setting::get('mail_password'),
                    'mail.mailers.smtp.encryption' => \App\Models\Setting::get('mail_encryption', 'tls') ?: null,
                    'mail.from.address' => \App\Models\Setting::get('mail_from_address', 'news@thetruedefender.news'),
                    'mail.from.name' => \App\Models\Setting::get('mail_from_name', 'TheTrueDefender'),
                ]);
            }
        } catch (\Throwable $e) {
            // Ignore during bootstrap before the DB is ready.
        }

        // Show/edit all admin date-time pickers in the admin's local timezone
        // (values persist as UTC). Prevents "publish now" landing in the future
        // on a UTC server, which would hide the post until that time passes.
        \Filament\Forms\Components\DateTimePicker::configureUsing(
            fn (\Filament\Forms\Components\DateTimePicker $picker) => $picker->timezone(config('app.admin_timezone'))
        );

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
