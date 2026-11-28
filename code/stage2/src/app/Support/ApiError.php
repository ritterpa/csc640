<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any error inside /api into the Stage 1 error envelope, so Laravel's
 * default HTML pages and its own JSON formats never reach API clients.
 * Registered in bootstrap/app.php.
 *
 * ApiException is not handled here: Laravel calls an exception's own render()
 * before any render callback, and ApiException::render() uses response()
 * below. So ApiException depends on this class, and not the other way round.
 */
final class ApiError
{
    /** @param array<string,string> $headers */
    public static function response(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            $headers + ['Cache-Control' => 'no-store'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return match (true) {
            // Thrown by the auth:jwt middleware: missing, malformed, badly signed, or expired token.
            $e instanceof AuthenticationException => self::response(
                401, 'UNAUTHORIZED', 'A valid bearer token is required.', ['WWW-Authenticate' => 'Bearer']
            ),

            // The path exists but not with this method (checked before authentication, as in Stage 1).
            $e instanceof MethodNotAllowedHttpException => self::response(
                405, 'METHOD_NOT_ALLOWED', 'This method is not allowed on this path.',
                array_intersect_key($e->getHeaders(), ['Allow' => true])
            ),

            $e instanceof NotFoundHttpException => self::response(404, 'NOT_FOUND', 'No API endpoint matches this path.'),

            $e instanceof HttpExceptionInterface => self::response(
                $e->getStatusCode(), 'HTTP_' . $e->getStatusCode(), $e->getMessage() ?: 'The request failed.'
            ),

            // Anything unexpected: logged by Laravel; details shown only when APP_DEBUG=true.
            default => self::response(
                500, 'INTERNAL_ERROR', config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.'
            ),
        };
    }
}
