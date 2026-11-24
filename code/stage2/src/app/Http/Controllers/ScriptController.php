<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\ApiInput;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/scripts             - script catalog
 * GET /api/v1/scripts/{scriptId}  - one script with run counts and latest run
 */
class ScriptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scripts = Script::query()->orderBy('ScriptID')->get();
        return response()->json(['data' => ScriptResource::collection($scripts)->resolve($request)]);
    }

    public function show(Request $request, string $scriptId): JsonResponse
    {
        $id = ApiInput::positiveInt($scriptId, 'scriptId');

        // withCount() counts the script's runs in the same query, once per status.
        $script = Script::query()->withCount([
            'runs as totalExecutions',
            'runs as successfulExecutions' => fn (Builder $q) => $q->where('Status', 'Success'),
            'runs as failedExecutions'     => fn (Builder $q) => $q->where('Status', 'Failed'),
            'runs as cancelledExecutions'  => fn (Builder $q) => $q->where('Status', 'Cancelled'),
            'runs as runningExecutions'    => fn (Builder $q) => $q->where('Status', 'Running'),
        ])->find($id);

        if ($script === null) {
            throw ApiException::notFound("Script $id was not found.");
        }

        $latest = $script->runs()->orderByDesc('StartTimeUtc')->first();

        return response()->json(['data' => (new ScriptResource($script))->resolve($request) + [
            'totalExecutions'      => (int) $script->totalExecutions,
            'successfulExecutions' => (int) $script->successfulExecutions,
            'failedExecutions'     => (int) $script->failedExecutions,
            'cancelledExecutions'  => (int) $script->cancelledExecutions,
            'runningExecutions'    => (int) $script->runningExecutions,
            'latestRun'            => $latest === null ? null : [
                'runId'        => $latest->RunID,
                'startTimeUtc' => Format::timestamp($latest->StartTimeUtc),
                'endTimeUtc'   => Format::timestamp($latest->EndTimeUtc),
                'status'       => $latest->Status,
            ],
        ]]);
    }
}
