<?php

namespace App\Models\Concerns;

use App\Support\DateInput;
use Illuminate\Database\Eloquent\Builder;

/**
 * The from/to filter used by run history, log entries, and the reports, in
 * one place. Models that use this trait get a withinDates() query scope:
 *
 *   Run::query()->withinDates('StartTimeUtc', DateInput::range($request->query()))
 *   $run->logEntries()->withinDates('TimestampUtc', $range)
 *
 * $range comes from DateInput::range(): a date-only "to" includes that whole
 * day (toExclusive is the next midnight); a timestamp "to" is inclusive.
 */
trait FiltersByDateRange
{
    /**
     * @param array{from: ?\DateTimeImmutable, toExclusive: ?\DateTimeImmutable, toInclusive: ?\DateTimeImmutable} $range
     */
    public function scopeWithinDates(Builder $query, string $column, array $range): void
    {
        $query
            ->when($range['from'], fn ($q, $from) => $q->where($column, '>=', DateInput::db($from)))
            ->when($range['toExclusive'], fn ($q, $to) => $q->where($column, '<', DateInput::db($to)))
            ->when($range['toInclusive'], fn ($q, $to) => $q->where($column, '<=', DateInput::db($to)));
    }
}
