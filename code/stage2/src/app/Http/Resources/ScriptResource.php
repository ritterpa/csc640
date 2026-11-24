<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a Script for the API: same camelCase fields as Stage 1.
 *
 * @mixin \App\Models\Script
 */
class ScriptResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'scriptId'        => (int) $this->ScriptID,
            'scriptName'      => $this->ScriptName,
            'description'     => $this->Description,
            'team'            => $this->Team,
            'owner'           => $this->Owner,
            'repository'      => $this->Repository,
            'createdDateUtc'  => Format::timestamp($this->CreatedDateUtc),
            'modifiedDateUtc' => Format::timestamp($this->ModifiedDateUtc),
        ];
    }
}
