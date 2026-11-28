<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;

/**
 * One script (GET /scripts/{scriptId}): the catalog fields plus its run
 * counts and latest run, same as Stage 1. The controller loads the counts
 * with withCount() and the latest run with ->with('latestRun').
 *
 * @mixin \App\Models\Script
 */
class ScriptDetailResource extends ScriptResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $latest = $this->latestRun;

        return parent::toArray($request) + [
            'totalExecutions'      => (int) $this->totalExecutions,
            'successfulExecutions' => (int) $this->successfulExecutions,
            'failedExecutions'     => (int) $this->failedExecutions,
            'cancelledExecutions'  => (int) $this->cancelledExecutions,
            'runningExecutions'    => (int) $this->runningExecutions,
            'latestRun'            => $latest === null ? null : [
                'runId'        => $latest->RunID,
                'startTimeUtc' => Format::timestamp($latest->StartTimeUtc),
                'endTimeUtc'   => Format::timestamp($latest->EndTimeUtc),
                'status'       => $latest->Status,
            ],
        ];
    }
}
