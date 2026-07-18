<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Record ?ref=CODE affiliate visits + attribution cookie on public pages.
        $middleware->web(append: [
            \App\Http\Middleware\TrackAffiliate::class,
        ]);

        // Unauthenticated affiliates go to the affiliate login (there is no
        // public login elsewhere on the site).
        $middleware->redirectGuestsTo(fn () => route('affiliate.login'));

        // Stripe posts webhooks without a CSRF token (signature-verified instead).
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
