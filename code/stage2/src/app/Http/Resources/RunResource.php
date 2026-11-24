<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;

/**
 * All fields of one run (GET /runs/{runId}): the summary plus the details,
 * same as Stage 1.
 *
 * @mixin \App\Models\Run
 */
class RunResource extends RunSummaryResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'scriptVersion'     => $this->ScriptVersion,
            'description'       => $this->Description,
            'powerShellVersion' => $this->PowerShellVersion,
            'psEdition'         => $this->PSEdition,
            'processId'         => Format::intOrNull($this->ProcessID),
            'correlationId'     => $this->CorrelationID,
            'parentRunId'       => $this->ParentRunID,
            'parameters'        => Format::json($this->Parameters),
            'gitCommit'         => $this->GitCommit,
            'scriptPath'        => $this->ScriptPath,
            'message'           => $this->Message,
        ];
    }
}
