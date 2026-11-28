<?php

namespace App\Http\Resources;

use App\Enums\RunStatus;
use App\Support\Format;
use Illuminate\Http\Request;

/**
 * One script (GET /scripts/{scriptId}): the catalog fields plus its run
 * counts and latest run, same as Stage 1. The controller loads the counts
 * with Script::withRunCounts() and the latest run with ->with('latestRun').
 *
 * @mixin \App\Models\Script
 */
class ScriptDetailResource extends ScriptResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        // totalExecutions, then one count per status (successfulExecutions, ...).
        $counts = ['totalExecutions' => (int) $this->totalExecutions];
        foreach (RunStatus::cases() as $status) {
            $counts[$status->countField()] = (int) $this->{$status->countField()};
        }
        $latest = $this->latestRun;

        return parent::toArray($request) + $counts + [
            'latestRun'            => $latest === null ? null : [
                'runId'        => $latest->RunID,
                'startTimeUtc' => Format::timestamp($latest->StartTimeUtc),
                'endTimeUtc'   => Format::timestamp($latest->EndTimeUtc),
                'status'       => $latest->Status,
            ],
        ];
    }
}
