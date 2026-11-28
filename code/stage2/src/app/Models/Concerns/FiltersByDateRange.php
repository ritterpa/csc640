<?php

namespace App\Models\Concerns;

use App\Support\DateInput;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;

/**
 * The from/to filter used by run history, log entries, and the reports, in
 * one place. Models that use this trait get a withinDates() query scope:
 *
 *   Run::query()->withinDates('StartTimeUtc', DateInput::range($request->query()))
 *   $run->logEntries()->withinDates('TimestampUtc', $range)
 *
 * DateRange decides whether "to" is included (a timestamp) or excluded (the
 * midnight after a date-only "to").
 */
trait FiltersByDateRange
{
    public function scopeWithinDates(Builder $query, string $column, DateRange $range): void
    {
        $query
            ->when($range->from, fn ($q, $from) => $q->where($column, '>=', DateInput::db($from)))
            ->when($range->to, fn ($q, $to) => $q->where($column, $range->toIsExclusive ? '<' : '<=', DateInput::db($to)));
    }
}
