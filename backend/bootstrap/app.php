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
        // The legacy `?object=controller.action` compat layer
        // (App\Http\Controllers\ObjectDispatchController, routes/web.php)
        // replicates the old admin-panel POST contract, which never used
        // Laravel's CSRF tokens — the old frontend won't send one. Without
        // this exemption every `campaigns.create`/`.update`/etc POST gets
        // rejected with a 419 before it ever reaches the controller.
        $middleware->validateCsrfTokens(except: [
            'admin/index.php',
        ]);

        // The legacy "states" auth cookie (App\Services\AuthService,
        // App\Http\Middleware\LegacyAuthMiddleware) IS the literal
        // "v1<jwt>" token — old frontend code (and our own AuthService)
        // reads/parses it directly, and it's deliberately `httpOnly=false`
        // so JS can read it too (see docs/legacy-reference/frontend/
        // backend_api_reference.md §4.1). Laravel's default cookie
        // encryption would wrap it in an opaque encrypted envelope,
        // silently breaking both of those — exclude it here the same way
        // CSRF is excluded above for this route.
        $middleware->encryptCookies(except: [
            'states',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
