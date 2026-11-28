<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Http\Resources\ExecutionSummaryResource;
use App\Http\Resources\FailingScriptResource;
use App\Models\Run;
use App\Models\Script;
use App\Reports\ExecutionSummary;
use App\Support\ApiInput;
use App\Support\DateInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/reports/execution-summary - totals by status, success rate, average duration
 * GET /api/v1/reports/failing-scripts   - scripts ranked by number of failed runs
 *
 * Both filter by run start time (from/to) and team. Everything is built with
 * Eloquent and the query builder: no raw SQL.
 */
class ReportController extends Controller
{
    public function executionSummary(Request $request): JsonResponse
    {
        $q = $request->query();
        $filters = ApiInput::validate($q, [
            'team'     => 'sometimes|string|max:100',
            'scriptId' => 'sometimes|integer|min:1|max:' . ApiInput::INT_MAX,
        ]);
        $range = DateInput::range($q);

        $runs = Run::query()
            ->when(isset($filters['scriptId']), fn ($b) => $b->where('ScriptID', (int) $filters['scriptId']))
            // whereHas filters runs through the run's script relationship.
            ->when(isset($filters['team']), fn ($b) => $b->whereHas('script', fn ($s) => $s->where('Team', $filters['team'])))
            ->withinDates('StartTimeUtc', $range);

        // ExecutionSummary computes the numbers; the resource formats them.
        $summary = ExecutionSummary::of($runs);

        return response()->json(['data' => (new ExecutionSummaryResource($summary))->resolve($request)]);
    }

    public function failingScripts(Request $request): JsonResponse
    {
        $q = $request->query();
        $filters = ApiInput::validate($q, [
            'team'  => 'sometimes|string|max:100',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        $range = DateInput::range($q);

        // Constraints on each script's runs: within the date range, and also failed.
        $inRange = fn (Builder $runs) => $runs->withinDates('StartTimeUtc', $range);
        $failedInRange = fn (Builder $runs) => $runs->withinDates('StartTimeUtc', $range)->where('Status', RunStatus::Failed);

        $scripts = Script::query()
            ->when(isset($filters['team']), fn ($b) => $b->where('Team', $filters['team']))
            ->withCount([
                'runs as totalExecutions' => $inRange,
                'runs as failureCount'    => $failedInRange,
            ])
            ->withMax(['runs as lastFailureEnd' => $failedInRange], 'EndTimeUtc')
            ->withMax(['runs as lastFailureStart' => $failedInRange], 'StartTimeUtc')
            ->having('failureCount', '>', 0)             // only scripts with at least one failure
            ->orderByDesc('failureCount')
            ->orderBy('totalExecutions')                 // equal failures: fewer runs = higher failure rate
            ->orderBy('ScriptName')
            ->limit((int) ($filters['limit'] ?? 10))
            ->get();

        return response()->json(['data' => FailingScriptResource::collection($scripts)->resolve($request)]);
    }
}
