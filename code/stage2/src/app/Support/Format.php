<?php

namespace App\Support;

use DateTimeInterface;
use DateTimeZone;

/**
 * Converts between database values and the API's formats, matching Stage 1:
 * timestamps as ISO 8601 UTC with milliseconds, and JSON values stored as
 * JSON text (jsonText) and returned as JSON values again (json).
 */
final class Format
{
    /**
     * 2026-11-01 08:00:00.000 -> "2026-11-01T08:00:00.000Z". Accepts a date
     * object (from a model's cast) or a database string (from an aggregate
     * such as withMax, which Eloquent doesn't cast).
     */
    public static function timestamp(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $utc = is_string($value)
            ? new \DateTimeImmutable($value, new DateTimeZone('UTC'))
            : \DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Text from a JSON column -> decoded value (object, array, or scalar), or
     * null. Objects stay objects (stdClass), so {} and {"0":"x"} are returned
     * as stored instead of as the arrays [] and ["x"].
     */
    public static function json(?string $text): mixed
    {
        return $text === null ? null : json_decode($text);
    }

    /**
     * Any JSON value from a request -> text for a JSON column (Parameters,
     * JsonData), or null when the value is null or missing. Slashes and
     * non-ASCII characters are stored as written, not escaped.
     */
    public static function jsonText(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
