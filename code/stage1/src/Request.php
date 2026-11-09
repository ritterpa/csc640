<?php
declare(strict_types=1);

namespace InfraLogging;

/**
 * The parts of the HTTP request the API uses: method, path, query string,
 * headers, and the raw body.
 */
final class Request
{
    /**
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers keys are lower-case header names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly array $headers,
        private readonly string $body
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        // Some PHP-FPM setups expose Authorization only under this name.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input')
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decodes the body as a JSON object. An empty body, invalid JSON, or a
     * JSON array or scalar is a 400.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('Request body must be valid JSON.');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }
        return $data;
    }
}
