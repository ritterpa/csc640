<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\RequireScope;
use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',     // added: the InfraLogging API under /api
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // added: ->middleware('scope:read') / ('scope:write') on routes
        $middleware->alias(['scope' => RequireScope::class]);
        
        // added: an API has no login page. Laravel's default sends rejected requests
        // to route('login'), which doesn't exist here and caused a 500. With no
        // redirect, a missing or invalid token becomes a 401 from ApiError.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // from Laravel's skeleton: errors under /api are rendered as JSON
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // added: expected API errors (400/401/403/404) are not logged as server errors
        $exceptions->dontReport([ApiException::class]);

        // added: every error under /api uses the Stage 1 error envelope
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? ApiError::fromThrowable($e) : null;
        });
    })->create();
