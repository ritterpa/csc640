<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The run fields used in lists (GET /runs), same as Stage 1.
 *
 * @mixin \App\Models\Run
 */
class RunSummaryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'runId'        => $this->RunID,
            'scriptId'     => (int) $this->ScriptID,
            'scriptName'   => $this->ScriptName,
            'startTimeUtc' => Format::timestamp($this->StartTimeUtc),
            'endTimeUtc'   => Format::timestamp($this->EndTimeUtc),
            'status'       => $this->Status,
            'durationMs'   => Format::intOrNull($this->DurationMS),
            'computerName' => $this->ComputerName,
            'userName'     => $this->UserName,
        ];
    }
}
