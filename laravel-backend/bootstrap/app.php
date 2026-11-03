<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'clinic.ensure' => \App\Http\Middleware\EnsureUserClinicMatch::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'audit' => \App\Http\Middleware\LogAuditActivity::class,
            'totp.enrolled' => \App\Http\Middleware\EnsureTotpEnrolled::class,
            'plan' => \App\Http\Middleware\EnsurePlanModule::class,
        ]);
        // The clinic context must exist before route models are resolved, so they are clinic-scoped too.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureUserClinicMatch::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
