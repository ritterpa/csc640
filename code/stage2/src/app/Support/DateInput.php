<?php

namespace App\Support;

use App\Exceptions\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Strict date parsing with the same rules as Stage 1 (src/Validator.php):
 * ISO 8601 timestamps such as 2026-11-01T08:00:00.000Z, and plain dates such
 * as 2026-11-01 where allowed. Rejects values like "yesterday" or 2026-02-30.
 * Plain PHP (no Laravel), so the same rules can be unit tested on their own.
 */
final class DateInput
{
    private const DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';
    private const DATETIME = '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * @return array{0: DateTimeImmutable, 1: bool} the value in UTC, and whether it was date-only
     */
    public static function parse(mixed $value, string $name, bool $allowDateOnly = false): array
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
     * Reads the optional ?from= and ?to= query values as a DateRange. Each may
     * be a date or a timestamp; a date-only "to" includes that whole day.
     *
     * @param array<string,mixed> $query
     */
    public static function range(array $query): DateRange
    {
        $from = isset($query['from']) ? self::parse($query['from'], 'from', true)[0] : null;
        [$to, $toIsDateOnly] = isset($query['to']) ? self::parse($query['to'], 'to', true) : [null, false];

        $range = DateRange::between($from, $to, $toIsDateOnly);
        if ($range->startsAfterEnd()) {
            throw ApiException::badRequest('from must not be after to.');
        }
        return $range;
    }

    /** Value for a DATETIME(3) column. */
    public static function db(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
