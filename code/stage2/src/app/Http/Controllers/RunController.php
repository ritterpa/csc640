<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\RunResource;
use App\Http\Resources\RunSummaryResource;
use App\Models\Run;
use App\Models\Script;
use App\Support\ApiInput;
use App\Support\DateInput;
use App\Support\Format;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET   /api/v1/runs          - run history, newest first, with filters and paging
 * POST  /api/v1/runs          - start a run (write scope)
 * GET   /api/v1/runs/{runId}  - one run
 * PATCH /api/v1/runs/{runId}  - finish or update a run (write scope)
 */
class RunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->query();
        $filters = ApiInput::validate($q, [
            'scriptId' => 'sometimes|integer|min:1|max:' . ApiInput::INT_MAX,
            'status'   => 'sometimes|string|in:' . implode(',', ApiInput::RUN_STATUSES),
        ]);
        $range = DateInput::range($q);
        $paging = ApiInput::paging($q);

        $query = Run::query()
            ->when(isset($filters['scriptId']), fn ($b) => $b->where('ScriptID', (int) $filters['scriptId']))
            ->when(isset($filters['status']), fn ($b) => $b->where('Status', $filters['status']))
            ->when($range['from'], fn ($b, $from) => $b->where('StartTimeUtc', '>=', DateInput::db($from)))
            ->when($range['toExclusive'], fn ($b, $to) => $b->where('StartTimeUtc', '<', DateInput::db($to)))
            ->when($range['toInclusive'], fn ($b, $to) => $b->where('StartTimeUtc', '<=', DateInput::db($to)));

        $total = (clone $query)->count();
        $runs = $query
            ->orderByDesc('StartTimeUtc')
            ->orderBy('RunID')
            ->skip(($paging['page'] - 1) * $paging['limit'])
            ->take($paging['limit'])
            ->get();

        return response()->json([
            'data'       => RunSummaryResource::collection($runs)->resolve($request),
            'pagination' => ['page' => $paging['page'], 'limit' => $paging['limit'], 'total' => $total],
        ]);
    }

    public function show(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $run = Run::find($id);
        if ($run === null) {
            throw ApiException::notFound("Run $id was not found.");
        }
        return response()->json(['data' => (new RunResource($run))->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $body = ApiInput::jsonObject($request);
        $input = ApiInput::validate($body, [
            'scriptName'        => 'required|string|max:200',
            'startTimeUtc'      => 'required',
            'scriptVersion'     => 'nullable|string|max:50',
            'description'       => 'nullable|string',
            'team'              => 'nullable|string|max:100',
            'repository'        => 'nullable|string|max:500',
            'owner'             => 'nullable|string|max:200',
            'computerName'      => 'nullable|string|max:255',
            'userName'          => 'nullable|string|max:255',
            'powerShellVersion' => 'nullable|string|max:50',
            'psEdition'         => 'nullable|string|max:50',
            'processId'         => 'nullable|integer',
            'correlationId'     => 'nullable|uuid',
            'parentRunId'       => 'nullable|uuid',
            'gitCommit'         => 'nullable|string|max:100',
            'scriptPath'        => 'nullable|string|max:1000',
        ]);
        [$start] = DateInput::parse($body['startTimeUtc'], 'startTimeUtc');

        // A parent run that doesn't exist is bad input, not a missing resource.
        $parentRunId = isset($input['parentRunId']) ? strtolower($input['parentRunId']) : null;
        if ($parentRunId !== null && ! Run::whereKey($parentRunId)->exists()) {
            throw ApiException::badRequest('parentRunId does not refer to an existing run.');
        }

        // Like Stage 1's stored procedure, register the script if it's new (or
        // update its details) and record the run in one transaction. lockForUpdate
        // keeps two simultaneous first runs of a script from both creating it.
        $run = DB::transaction(function () use ($input, $body, $start, $parentRunId) {
            $details = array_filter([
                'Description' => $input['description'] ?? null,
                'Team'        => $input['team'] ?? null,
                'Repository'  => $input['repository'] ?? null,
                'Owner'       => $input['owner'] ?? null,
            ], fn ($value) => $value !== null);

            $script = Script::where('ScriptName', $input['scriptName'])->lockForUpdate()->first();
            if ($script === null) {
                $script = Script::create(['ScriptName' => $input['scriptName']] + $details);
            } else {
                $script->fill($details);
                $script->ModifiedDateUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $script->save();
            }

            return Run::create([
                'RunID'             => Str::uuid()->toString(),
                'ScriptID'          => $script->ScriptID,
                'ScriptName'        => $script->ScriptName,
                'ScriptVersion'     => $input['scriptVersion'] ?? null,
                'Description'       => $input['description'] ?? null,
                'ComputerName'      => $input['computerName'] ?? null,
                'UserName'          => $input['userName'] ?? null,
                'StartTimeUtc'      => DateInput::db($start),
                'Status'            => 'Running',
                'PowerShellVersion' => $input['powerShellVersion'] ?? null,
                'PSEdition'         => $input['psEdition'] ?? null,
                'ProcessID'         => $input['processId'] ?? null,
                'CorrelationID'     => strtolower($input['correlationId'] ?? Str::uuid()->toString()),
                'ParentRunID'       => $parentRunId,
                // Any JSON value, stored as text in the Parameters column.
                'Parameters'        => isset($body['parameters'])
                    ? json_encode($body['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'GitCommit'         => $input['gitCommit'] ?? null,
                'ScriptPath'        => $input['scriptPath'] ?? null,
            ]);
        });

        return response()->json(['data' => [
            'runId'         => $run->RunID,
            'scriptId'      => (int) $run->ScriptID,
            'scriptName'    => $run->ScriptName,
            'startTimeUtc'  => Format::timestamp($run->StartTimeUtc),
            'status'        => $run->Status,
            'correlationId' => $run->CorrelationID,
        ]], 201, ['Location' => "/api/v1/runs/{$run->RunID}"]);
    }

    public function update(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $body = ApiInput::jsonObject($request);
        $input = ApiInput::validate($body, [
            'status'  => 'required|string|in:' . implode(',', ApiInput::FINAL_STATUSES),
            'message' => 'nullable|string',
        ]);
        $end = isset($body['endTimeUtc'])
            ? DateInput::parse($body['endTimeUtc'], 'endTimeUtc')[0]
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $run = Run::find($id) ?? throw ApiException::notFound("Run $id was not found.");

        $start = DateTimeImmutable::createFromInterface($run->StartTimeUtc);
        if ($end < $start) {
            throw ApiException::badRequest('endTimeUtc must not be before the run start time.');
        }

        // The server computes the duration in milliseconds ("Uv" = seconds and milliseconds).
        $run->update([
            'EndTimeUtc' => DateInput::db($end),
            'Status'     => $input['status'],
            'DurationMS' => (int) $end->format('Uv') - (int) $start->format('Uv'),
            'Message'    => $input['message'] ?? null,
        ]);

        return response()->json(['data' => (new RunResource($run->refresh()))->resolve($request)]);
    }
}
