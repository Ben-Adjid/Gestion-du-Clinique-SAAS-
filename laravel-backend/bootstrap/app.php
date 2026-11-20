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

        // Users never see the framework's English text, a model class name or a stack trace.
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        // Laravel turns a missing model into an HTTP 404 before rendering: the model name must not leak.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['message' => 'Élément introuvable ou non accessible.'], 404);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($isApi) {
            $expected = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \App\Exceptions\SubscriptionBlockedException;
            if ($expected || !$isApi($request)) {
                return null;
            }

            return response()->json(['message' => 'Une erreur interne est survenue. Réessayez ; si le problème persiste, prévenez le support.'], 500);
        });
    })->create();
