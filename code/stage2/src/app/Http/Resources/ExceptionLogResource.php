<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats an exception captured during a run: same fields as Stage 1.
 *
 * @mixin \App\Models\ExceptionLog
 */
class ExceptionLogResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'exceptionId'           => (int) $this->ExceptionID,
            'runId'                 => $this->RunID,
            'timestampUtc'          => Format::timestamp($this->TimestampUtc),
            'exceptionType'         => $this->ExceptionType,
            'message'               => $this->Message,
            'stackTrace'            => $this->StackTrace,
            'scriptStackTrace'      => $this->ScriptStackTrace,
            'fullyQualifiedErrorId' => $this->FullyQualifiedErrorId,
            'category'              => $this->Category,
            'invocationInfo'        => Format::json($this->InvocationInfo),
            'jsonData'              => Format::json($this->JsonData),
        ];
    }
}
