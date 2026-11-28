<?php

namespace App\Actions;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Script;
use App\Support\DateInput;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a run: the business rule behind POST /api/v1/runs.
 *
 * Like Stage 1's stored procedure, it registers the script if it's new (or
 * updates its details) and records the run in one transaction. lockForUpdate
 * keeps two simultaneous first runs of a script from both creating it.
 *
 * The controller checks the request first; this class takes values that are
 * already validated and knows nothing about HTTP.
 */
class StartRun
{
    /**
     * @param array<string,mixed> $input validated request fields (camelCase API names)
     * @param mixed $parameters any JSON value, stored as text in the Parameters column
     */
    public function __invoke(array $input, DateTimeImmutable $start, ?string $parentRunId, mixed $parameters): Run
    {
        return DB::transaction(function () use ($input, $start, $parentRunId, $parameters) {
            $script = $this->registerScript($input);

            return Run::create([
                'RunID'             => Str::uuid()->toString(),
                'ScriptID'          => $script->ScriptID,
                'ScriptName'        => $script->ScriptName,
                'ScriptVersion'     => $input['scriptVersion'] ?? null,
                'Description'       => $input['description'] ?? null,
                'ComputerName'      => $input['computerName'] ?? null,
                'UserName'          => $input['userName'] ?? null,
                'StartTimeUtc'      => DateInput::db($start),
                'Status'            => RunStatus::Running->value,
                'PowerShellVersion' => $input['powerShellVersion'] ?? null,
                'PSEdition'         => $input['psEdition'] ?? null,
                'ProcessID'         => $input['processId'] ?? null,
                'CorrelationID'     => strtolower($input['correlationId'] ?? Str::uuid()->toString()),
                'ParentRunID'       => $parentRunId,
                'Parameters'        => $parameters !== null
                    ? json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'GitCommit'         => $input['gitCommit'] ?? null,
                'ScriptPath'        => $input['scriptPath'] ?? null,
            ]);
        });
    }

    /**
     * Finds the script by name (locking its row) and updates the details the
     * request supplied, or creates it. Must run inside the transaction.
     *
     * @param array<string,mixed> $input
     */
    private function registerScript(array $input): Script
    {
        $details = array_filter([
            'Description' => $input['description'] ?? null,
            'Team'        => $input['team'] ?? null,
            'Repository'  => $input['repository'] ?? null,
            'Owner'       => $input['owner'] ?? null,
        ], fn ($value) => $value !== null);

        $script = Script::where('ScriptName', $input['scriptName'])->lockForUpdate()->first();
        if ($script === null) {
            return Script::create(['ScriptName' => $input['scriptName']] + $details);
        }

        $script->fill($details);
        $script->ModifiedDateUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $script->save();
        return $script;
    }
}
