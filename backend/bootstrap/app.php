<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\AuthenticateIotDevice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(function (Request $request) {
            // API clients must receive Laravel's JSON 401 response instead of
            // attempting to redirect to the default web login route, which is
            // intentionally not part of this API-only authentication surface.
            return $request->is('api/*') ? null : route('login');
        });
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'iot.device' => AuthenticateIotDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $exception) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
