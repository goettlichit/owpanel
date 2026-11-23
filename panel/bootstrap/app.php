<?php

use App\Http\Middleware\EnsureAccountNotLocked;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\GuardSuspendedSites;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
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
        // Administration and customer area (E-143).
        $middleware->alias(['role' => EnsureRole::class]);
        // On every answer, also error pages outside the routes (E-157).
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            SetLocale::class,
            EnsureAccountNotLocked::class,
            EnsurePasswordChanged::class,
            EnsureTwoFactorForAdmins::class,
            // After the route parameters are bound (E-127).
            GuardSuspendedSites::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
