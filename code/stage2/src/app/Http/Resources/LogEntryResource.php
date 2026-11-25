<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a log entry for the API: same fields as Stage 1.
 *
 * @mixin \App\Models\LogEntry
 */
class LogEntryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'logId'        => (int) $this->LogID,
            'runId'        => $this->RunID,
            'timestampUtc' => Format::timestamp($this->TimestampUtc),
            'level'        => $this->Level,
            'eventName'    => $this->EventName,
            'message'      => $this->Message,
            'category'     => $this->Category,
            'functionName' => $this->FunctionName,
            'lineNumber'   => Format::intOrNull($this->LineNumber),
            'durationMs'   => Format::intOrNull($this->DurationMS),
            'jsonData'     => Format::json($this->JsonData),
        ];
    }
}
