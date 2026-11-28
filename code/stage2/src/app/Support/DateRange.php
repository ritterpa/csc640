<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * A from/to filter on a date column, as given by the ?from= and ?to= query
 * values (see DateInput::range). Either end may be open (null).
 *
 * It holds the rule for a date-only "to" (such as to=2026-11-02): that whole
 * day is included, so the range ends before the next midnight (exclusive).
 * A timestamp "to" is included as given. Plain PHP (no Laravel), like
 * DateInput, so it can be unit tested on its own.
 */
final class DateRange
{
    private function __construct(
        public readonly ?DateTimeImmutable $from,
        public readonly ?DateTimeImmutable $to,
        public readonly bool $toIsExclusive,
    ) {}

    /**
     * @param ?DateTimeImmutable $from          start, included
     * @param ?DateTimeImmutable $to            end as given
     * @param bool               $toIsDateOnly  $to was a plain date: include that whole day
     */
    public static function between(?DateTimeImmutable $from, ?DateTimeImmutable $to, bool $toIsDateOnly = false): self
    {
        return $toIsDateOnly && $to !== null
            ? new self($from, $to->modify('+1 day'), true)
            : new self($from, $to, false);
    }

    /** Whether "from" is after "to", so the range would contain nothing. */
    public function startsAfterEnd(): bool
    {
        if ($this->from === null || $this->to === null) {
            return false;
        }
        return $this->toIsExclusive ? $this->from >= $this->to : $this->from > $this->to;
    }
}
