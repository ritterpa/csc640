<?php

namespace App\Http\Controllers;

use App\Actions\StartRun;
use App\Exceptions\ApiException;
use App\Http\Resources\RunResource;
use App\Http\Resources\RunSummaryResource;
use App\Models\Run;
use App\Support\ApiInput;
use App\Support\DateInput;
use App\Support\Format;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Checks the request, then StartRun (injected by Laravel) records the run. */
    public function store(Request $request, StartRun $startRun): JsonResponse
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

        $run = $startRun($input, $start, $parentRunId, $body['parameters'] ?? null);

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

        // An end before the start is bad input (400); the model computes the duration.
        if ($end < $run->StartTimeUtc) {
            throw ApiException::badRequest('endTimeUtc must not be before the run start time.');
        }

        $run->finish($end, $input['status'], $input['message'] ?? null);

        return response()->json(['data' => (new RunResource($run->refresh()))->resolve($request)]);
    }
}
