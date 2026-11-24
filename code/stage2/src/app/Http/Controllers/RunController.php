<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\RunResource;
use App\Http\Resources\RunSummaryResource;
use App\Models\Run;
use App\Support\ApiInput;
use App\Support\DateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/runs          - run history, newest first, with filters and paging
 * GET /api/v1/runs/{runId}  - one run
 *
 * (POST /runs and PATCH /runs/{runId} are added in Stage 2 Milestone 2.)
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
}
