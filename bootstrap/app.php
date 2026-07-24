<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/mailbox.php',
        apiPrefix: 'api/mailbox',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Coolify's reverse proxy (TLS terminated there): trust the
        // X-Forwarded-* headers so Laravel knows requests are HTTPS.
        $middleware->trustProxies(at: '*');

        // Apply the per-session UI locale.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // The Cloudflare Email Worker posts here with its own HMAC signature.
        $middleware->validateCsrfTokens(except: [
            'api/cf/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
