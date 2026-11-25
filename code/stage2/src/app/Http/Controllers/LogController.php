<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\LogEntryResource;
use App\Models\Run;
use App\Support\ApiInput;
use App\Support\DateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /api/v1/runs/{runId}/logs  - log entries for a run, oldest first (filters)
 *
 * (POST /runs/{runId}/logs is added in the next commit.)
 */
class LogController extends Controller
{
    public function index(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $q = $request->query();
        $filters = ApiInput::validate($q, [
            'level'     => 'sometimes|string|in:' . implode(',', ApiInput::LOG_LEVELS),
            'eventName' => 'sometimes|string|max:200',
        ]);
        $range = DateInput::range($q);

        // Input is checked before existence, as in Stage 1: bad input is a 400 even for an unknown run.
        $run = Run::find($id) ?? throw ApiException::notFound("Run $id was not found.");

        $logs = $run->logEntries()
            ->when(isset($filters['level']), fn ($b) => $b->where('Level', $filters['level']))
            ->when(isset($filters['eventName']), fn ($b) => $b->where('EventName', $filters['eventName']))
            ->when($range['from'], fn ($b, $from) => $b->where('TimestampUtc', '>=', DateInput::db($from)))
            ->when($range['toExclusive'], fn ($b, $to) => $b->where('TimestampUtc', '<', DateInput::db($to)))
            ->when($range['toInclusive'], fn ($b, $to) => $b->where('TimestampUtc', '<=', DateInput::db($to)))
            ->orderBy('TimestampUtc')
            ->orderBy('LogID')
            ->get();

        return response()->json(['data' => LogEntryResource::collection($logs)->resolve($request)]);
    }
}
