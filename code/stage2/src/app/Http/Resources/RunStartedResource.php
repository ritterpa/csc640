<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The response to starting a run (POST /runs, 201): the new run's id and
 * the values the server filled in, same as Stage 1.
 *
 * @mixin \App\Models\Run
 */
class RunStartedResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'runId'         => $this->RunID,
            'scriptId'      => (int) $this->ScriptID,
            'scriptName'    => $this->ScriptName,
            'startTimeUtc'  => Format::timestamp($this->StartTimeUtc),
            'status'        => $this->Status,
            'correlationId' => $this->CorrelationID,
        ];
    }
}
