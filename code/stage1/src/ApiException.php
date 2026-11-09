<?php
declare(strict_types=1);

namespace InfraLogging;

use Exception;

/**
 * An expected error that becomes a JSON error response:
 * {"error": {"code": ..., "message": ...}} with the given HTTP status.
 */
final class ApiException extends Exception
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
}
