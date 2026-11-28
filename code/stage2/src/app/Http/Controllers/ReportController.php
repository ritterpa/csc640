<?php

namespace App\Http\Controllers;

use App\Http\Resources\FailingScriptResource;
use App\Models\Run;
use App\Models\Script;
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
            ->tap(fn ($b) => self::startedWithin($b, $range));

        $count = fn (string $status) => (clone $runs)->where('Status', $status)->count();
        $success = $count('Success');
        $failed = $count('Failed');
        $cancelled = $count('Cancelled');
        $finished = $success + $failed + $cancelled;
        // Average over finished runs only (a running run has no duration yet).
        $average = (clone $runs)->whereIn('Status', ApiInput::FINAL_STATUSES)->avg('DurationMS');

        return response()->json(['data' => [
            'totalExecutions'      => (clone $runs)->count(),
            'successfulExecutions' => $success,
            'failedExecutions'     => $failed,
            'cancelledExecutions'  => $cancelled,
            'runningExecutions'    => $count('Running'),
            'averageDurationMs'    => $average === null ? null : (int) round((float) $average),
            // successful / (successful + failed + cancelled); null when nothing has finished.
            'successRate'          => $finished === 0 ? null : round($success / $finished, 4),
        ]]);
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
        $inRange = fn (Builder $runs) => self::startedWithin($runs, $range);
        $failedInRange = fn (Builder $runs) => self::startedWithin($runs, $range)->where('Status', 'Failed');

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

    /**
     * Limits runs to those that started within the from/to range.
     *
     * @param array{from: ?\DateTimeImmutable, toExclusive: ?\DateTimeImmutable, toInclusive: ?\DateTimeImmutable} $range
     */
    private static function startedWithin(Builder $runs, array $range): Builder
    {
        return $runs
            ->when($range['from'], fn ($b, $from) => $b->where('StartTimeUtc', '>=', DateInput::db($from)))
            ->when($range['toExclusive'], fn ($b, $to) => $b->where('StartTimeUtc', '<', DateInput::db($to)))
            ->when($range['toInclusive'], fn ($b, $to) => $b->where('StartTimeUtc', '<=', DateInput::db($to)));
    }
}
