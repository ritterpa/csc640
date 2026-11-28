<?php

namespace App\Http\Controllers;

use App\Enums\LogLevel;
use App\Exceptions\ApiException;
use App\Http\Resources\LogEntryResource;
use App\Models\Run;
use App\Support\ApiInput;
use App\Support\DateInput;
use App\Support\Format;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET  /api/v1/runs/{runId}/logs  - log entries for a run, oldest first (filters)
 * POST /api/v1/runs/{runId}/logs  - add a log entry (write scope)
 */
class LogController extends Controller
{
    public function index(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $q = $request->query();
        $filters = ApiInput::validate($q, [
            'level'     => ['sometimes', 'string', Rule::enum(LogLevel::class)],
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

    public function store(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $body = ApiInput::jsonObject($request);
        $input = ApiInput::validate($body, [
            'level'        => ['required', 'string', Rule::enum(LogLevel::class)],
            'eventName'    => 'nullable|string|max:200',
            'message'      => 'nullable|string',
            'category'     => 'nullable|string|max:100',
            'functionName' => 'nullable|string|max:255',
            'lineNumber'   => 'nullable|integer',
            'durationMs'   => 'nullable|integer',
        ]);
        $timestamp = isset($body['timestampUtc'])
            ? DateInput::parse($body['timestampUtc'], 'timestampUtc')[0]
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $run = Run::find($id) ?? throw ApiException::notFound("Run $id was not found.");

        // Creating through the relationship fills in RunID.
        $entry = $run->logEntries()->create([
            'TimestampUtc' => DateInput::db($timestamp),
            'Level'        => $input['level'],
            'EventName'    => $input['eventName'] ?? null,
            'Message'      => $input['message'] ?? null,
            'Category'     => $input['category'] ?? null,
            'FunctionName' => $input['functionName'] ?? null,
            'LineNumber'   => $input['lineNumber'] ?? null,
            'DurationMS'   => $input['durationMs'] ?? null,
            // Any JSON value (usually an object), stored as text in the JsonData column.
            'JsonData'     => Format::jsonText(ApiInput::jsonValue($request, 'jsonData')),
        ]);

        return response()->json(['data' => (new LogEntryResource($entry))->resolve($request)], 201);
    }
}
