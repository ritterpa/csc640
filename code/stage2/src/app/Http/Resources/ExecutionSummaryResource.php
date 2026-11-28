<?php

namespace App\Http\Resources;

use App\Enums\RunStatus;
use App\Reports\ExecutionSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats an ExecutionSummary (GET /reports/execution-summary), same fields
 * as Stage 1: totalExecutions, one count per status (successfulExecutions,
 * ...), averageDurationMs as whole milliseconds, and successRate rounded to
 * 4 decimal places.
 */
class ExecutionSummaryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExecutionSummary $summary */
        $summary = $this->resource;

        $counts = [];
        foreach (RunStatus::cases() as $status) {
            $counts[$status->countField()] = $summary->count($status);
        }
        $rate = $summary->successRate();

        return ['totalExecutions' => $summary->total] + $counts + [
            'averageDurationMs' => $summary->averageDurationMs === null ? null : (int) round($summary->averageDurationMs),
            'successRate'       => $rate === null ? null : round($rate, 4),
        ];
    }
}
