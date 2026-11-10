<?php
declare(strict_types=1);

namespace InfraLogging;

use RuntimeException;

/**
 * Reads KEY=VALUE pairs from the .env file. Real environment variables
 * take precedence over the file.
 */
final class Config
{
    /** @param array<string,string> $values */
    private function __construct(private array $values) {}

    public static function load(string $path): self
    {
        $values = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $values[$key] = trim($value, "\"'");
            }
        }
        return new self($values);
    }

    public function get(string $key, ?string $default = null): string
    {
        $env = getenv($key);
        $value = $env !== false ? $env : ($this->values[$key] ?? $default);
        if ($value === null) {
            throw new RuntimeException("Missing configuration value: $key");
        }
        return $value;
    }

    public function int(string $key, int $default): int
    {
        return (int) $this->get($key, (string) $default);
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower($this->get($key, 'false')), ['1', 'true', 'yes', 'on'], true);
    }
}
