<?php

namespace App\Reports;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers behind GET /reports/execution-summary, computed from a set of
 * runs: how many there are in total and per status, the average duration of
 * finished runs, and the success rate. ExecutionSummaryResource formats it
 * for the API; the controller only chooses which runs to summarize.
 */
final class ExecutionSummary
{
    /** @param array<string,int> $byStatus run count per RunStatus value */
    private function __construct(
        public readonly int $total,
        private readonly array $byStatus,
        public readonly ?float $averageDurationMs,
    ) {}

    /** Summarizes the runs the query selects (filters already applied). */
    public static function of(Builder $runs): self
    {
        $byStatus = [];
        foreach (RunStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $runs)->where('Status', $status)->count();
        }
        // Average over finished runs only: a running run has no duration yet.
        $average = (clone $runs)->whereIn('Status', RunStatus::finals())->avg('DurationMS');

        return new self((clone $runs)->count(), $byStatus, $average === null ? null : (float) $average);
    }

    public function count(RunStatus $status): int
    {
        return $this->byStatus[$status->value];
    }

    /** Successful runs / finished runs, or null when no run has finished. */
    public function successRate(): ?float
    {
        $finished = array_sum(array_map(fn (RunStatus $status) => $this->count($status), RunStatus::finals()));
        return $finished === 0 ? null : $this->count(RunStatus::Success) / $finished;
    }
}
