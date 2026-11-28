<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the failing-scripts report (GET /reports/failing-scripts),
 * same fields as Stage 1. The controller loads failureCount, totalExecutions,
 * lastFailureEnd and lastFailureStart with withCount() and withMax(), and
 * only returns scripts with at least one run, so failureRate never divides by 0.
 *
 * @mixin \App\Models\Script
 */
class FailingScriptResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'scriptId'        => (int) $this->ScriptID,
            'scriptName'      => $this->ScriptName,
            'team'            => $this->Team,
            'failureCount'    => (int) $this->failureCount,
            'totalExecutions' => (int) $this->totalExecutions,
            'failureRate'     => round($this->failureCount / $this->totalExecutions, 4),
            // When the latest failure ended, or when it started if it has no end time.
            'lastFailureUtc'  => Format::timestamp($this->lastFailureEnd ?? $this->lastFailureStart),
        ];
    }
}
