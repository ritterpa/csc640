<?php
declare(strict_types=1);

namespace InfraLogging;

/**
 * A JSON response. Every response body, including errors, is JSON.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly mixed $body,
        private readonly array $headers = []
    ) {}

    /** @param array<string,string> $headers */
    public static function json(int $status, mixed $body, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** @param array<string,string> $headers */
    public static function error(int $status, string $code, string $message, array $headers = []): self
    {
        return new self($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
