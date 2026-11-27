<?php

namespace App\Support;

use DateTimeInterface;
use DateTimeZone;

/**
 * Converts database values to the API's formats, matching Stage 1:
 * timestamps as ISO 8601 UTC with milliseconds, JSON text as JSON values.
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

    /** Text from a JSON column -> decoded value (object, array, or scalar), or null */
    public static function json(?string $text): mixed
    {
        return $text === null ? null : json_decode($text, true);
    }

    public static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
