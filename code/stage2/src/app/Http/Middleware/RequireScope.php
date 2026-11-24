<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a scope in the bearer token, for example ->middleware('scope:write').
 * Runs after auth:jwt, so the order of checks matches Stage 1:
 * token (401), then scope (403), then input (400), then existence (404).
 */
class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $granted = preg_split('/\s+/', trim((string) ($request->user('jwt')?->scope ?? '')));
        if (! in_array($scope, $granted, true)) {
            throw ApiException::forbidden("This endpoint requires the '$scope' scope.");
        }
        return $next($request);
    }
}
