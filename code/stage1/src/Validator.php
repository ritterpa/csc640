<?php
declare(strict_types=1);

namespace InfraLogging;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Input checks shared by the controllers. Every failure is a 400 with a
 * message naming the field, so bad input never reaches the database.
 */
final class Validator
{
    public const RUN_STATUSES = ['Running', 'Success', 'Failed', 'Cancelled'];
    public const FINAL_STATUSES = ['Success', 'Failed', 'Cancelled'];
    public const LOG_LEVELS = ['Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical'];

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const DATETIME = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';
    private const INT_MAX = 2147483647;

    public static function guid(mixed $value, string $name): string
    {
        if (!is_string($value) || !preg_match(self::GUID, $value)) {
            throw ApiException::badRequest("$name must be a GUID.");
        }
        return strtolower($value);
    }

    /** A positive integer given as a JSON number or a string of digits. */
    public static function positiveInt(mixed $value, string $name, int $max = self::INT_MAX): int
    {
        if (is_string($value) && preg_match('/^\d{1,10}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw ApiException::badRequest("$name must be an integer from 1 to $max.");
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public static function oneOf(mixed $value, array $allowed, string $name): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw ApiException::badRequest("$name must be one of: " . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    /**
     * Parses an ISO 8601 timestamp (2026-11-01T08:00:00.000Z or with an
     * offset) and, if $allowDateOnly, a plain date (2026-11-01). Returns the
     * value in UTC and whether it was date-only.
     *
     * @return array{0: DateTimeImmutable, 1: bool}
     */
    public static function dateTime(mixed $value, string $name, bool $allowDateOnly = false): array
    {
        $utc = new DateTimeZone('UTC');
        if (is_string($value) && $allowDateOnly && preg_match(self::DATE, $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [new DateTimeImmutable($value . 'T00:00:00', $utc), true];
        }
        if (is_string($value) && preg_match(self::DATETIME, $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            try {
                return [(new DateTimeImmutable($value))->setTimezone($utc), false];
            } catch (\Exception) {
                // fall through to the error below
            }
        }
        $format = $allowDateOnly ? 'YYYY-MM-DD or an ISO 8601 UTC timestamp' : 'an ISO 8601 UTC timestamp';
        throw ApiException::badRequest("$name must be $format.");
    }

    /**
     * Reads optional from/to query parameters. A date-only "to" includes the
     * whole day. Rejects a range where from is after to.
     *
     * @param array<string,mixed> $query
     * @return array{from: ?DateTimeImmutable, toExclusive: ?DateTimeImmutable, toInclusive: ?DateTimeImmutable}
     */
    public static function dateRange(array $query): array
    {
        $from = isset($query['from']) ? self::dateTime($query['from'], 'from', true)[0] : null;
        $toExclusive = $toInclusive = null;
        if (isset($query['to'])) {
            [$to, $dateOnly] = self::dateTime($query['to'], 'to', true);
            if ($dateOnly) {
                $toExclusive = $to->modify('+1 day');
            } else {
                $toInclusive = $to;
            }
        }
        if ($from !== null && (($toExclusive !== null && $from >= $toExclusive)
                || ($toInclusive !== null && $from > $toInclusive))) {
            throw ApiException::badRequest('from must not be after to.');
        }
        return ['from' => $from, 'toExclusive' => $toExclusive, 'toInclusive' => $toInclusive];
    }

    /** @param array<string,mixed> $source */
    public static function requiredString(array $source, string $key, int $maxLength): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ApiException::badRequest("$key is required.");
        }
        return self::checkLength($value, $key, $maxLength);
    }

    /** @param array<string,mixed> $source */
    public static function optionalString(array $source, string $key, ?int $maxLength = null): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::badRequest("$key must be a string.");
        }
        return $maxLength === null ? $value : self::checkLength($value, $key, $maxLength);
    }

    /** @param array<string,mixed> $source */
    public static function optionalInt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < -self::INT_MAX - 1 || $value > self::INT_MAX) {
            throw ApiException::badRequest("$key must be an integer.");
        }
        return $value;
    }

    /**
     * Any JSON value (usually an object) to store in a JSON text column.
     *
     * @param array<string,mixed> $source
     */
    public static function optionalJson(array $source, string $key): ?string
    {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        return json_encode($source[$key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reads ?page= and ?limit= (defaults 1 and $defaultLimit, limit at most 100).
     *
     * @param array<string,mixed> $query
     * @return array{page:int, limit:int}
     */
    public static function paging(array $query, int $defaultLimit = 50): array
    {
        return [
            'page'  => isset($query['page']) ? self::positiveInt($query['page'], 'page') : 1,
            'limit' => isset($query['limit']) ? self::positiveInt($query['limit'], 'limit', 100) : $defaultLimit,
        ];
    }

    private static function checkLength(string $value, string $key, int $maxLength): string
    {
        // Count characters, not bytes (VARCHAR limits are in characters).
        if (preg_match_all('/./su', $value) > $maxLength) {
            throw ApiException::badRequest("$key must be at most $maxLength characters.");
        }
        return $value;
    }
}
