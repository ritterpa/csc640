<?php

namespace App\Exceptions;

use App\Support\ApiError;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * An expected API error, rendered as the Stage 1 error envelope:
 * {"error": {"code": ..., "message": ...}} with the given HTTP status.
 * Laravel calls render() automatically when this exception is thrown.
 */
class ApiException extends Exception
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'BAD_REQUEST', $message);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, 'UNAUTHORIZED', $message, ['WWW-Authenticate' => 'Bearer']);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public function render(): JsonResponse
    {
        return ApiError::response($this->status, $this->errorCode, $this->getMessage(), $this->headers);
    }
}
