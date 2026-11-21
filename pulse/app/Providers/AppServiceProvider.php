<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\PageSeo;
use App\Models\Post;
use App\Models\Setting;
use App\Support\AiTransport;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
        Http::globalRequestMiddleware(function ($request) {
            $host = strtolower($request->getUri()->getHost());
            if (AiTransport::local() && ($host === 'api.openai.com' || str_ends_with($host, '.openai.com'))) {
                throw new \RuntimeException('Paid AI request blocked in local mode');
            }

            return $request;
        });
        // The "admin" role bypasses every permission check (super admin).
        Gate::before(fn ($user) => $user->hasRole('admin') ? true : null);

        // Apply admin-configured mail settings at runtime (so email can be set up
        // from the panel without editing .env). Resend (API key) takes priority;
        // otherwise fall back to raw SMTP. Guarded — the table may not exist yet
        // during install/migrate.
        try {
            if (Schema::hasTable('settings')) {
                $resendKey = Setting::get('resend_key');
                $smtpHost = Setting::get('mail_host');

                if (filled($resendKey)) {
                    config([
                        'mail.default' => 'resend',
                        'services.resend.key' => $resendKey,
                    ]);
                } elseif (filled($smtpHost)) {
                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp.host' => $smtpHost,
                        'mail.mailers.smtp.port' => (int) Setting::get('mail_port', 587),
                        'mail.mailers.smtp.username' => Setting::get('mail_username'),
                        'mail.mailers.smtp.password' => Setting::get('mail_password'),
                        'mail.mailers.smtp.encryption' => Setting::get('mail_encryption', 'tls') ?: null,
                    ]);
                }

                // From address/name apply to whichever transport is active.
                if (filled($resendKey) || filled($smtpHost)) {
                    config([
                        'mail.from.address' => Setting::get('mail_from_address', 'news@thetruedefender.news'),
                        'mail.from.name' => Setting::get('mail_from_name', 'TheTrueDefender'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Ignore during bootstrap before the DB is ready.
        }

        // Show/edit all admin date-time pickers in the admin's local timezone
        // (values persist as UTC). Prevents "publish now" landing in the future
        // on a UTC server, which would hide the post until that time passes.
        DateTimePicker::configureUsing(
            fn (DateTimePicker $picker) => $picker->timezone(config('app.admin_timezone'))
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
                'page' => Route::current()?->parameter('slug'),
                default => null,
            };

            $view->with('pageSeo', $key ? PageSeo::forKey($key) : null);
        });
    }
}
