<?php
declare(strict_types=1);

namespace InfraLogging;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts between database values and API values: DATETIME(3) columns
 * become ISO 8601 UTC strings, JSON text columns become JSON values.
 */
final class Format
{
    /** "2026-11-01 08:00:00.000" -> "2026-11-01T08:00:00.000Z" */
    public static function timestamp(?string $dbValue): ?string
    {
        if ($dbValue === null) {
            return null;
        }
        return (new DateTimeImmutable($dbValue, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** DateTimeImmutable -> value for a DATETIME(3) column */
    public static function dbTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** Text from a JSON column -> decoded value (object/array/scalar), or null */
    public static function json(?string $text): mixed
    {
        return $text === null ? null : json_decode($text, true);
    }

    public static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** Generates a random version 4 GUID for new run IDs. */
    public static function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
