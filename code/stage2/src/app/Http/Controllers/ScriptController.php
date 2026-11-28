<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\ScriptDetailResource;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\ApiInput;
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

        // withCount() counts the script's runs in the same query, once per status;
        // with('latestRun') loads the most recent run. ScriptDetailResource formats both.
        $script = Script::query()->withCount([
            'runs as totalExecutions',
            'runs as successfulExecutions' => fn (Builder $q) => $q->where('Status', RunStatus::Success),
            'runs as failedExecutions'     => fn (Builder $q) => $q->where('Status', RunStatus::Failed),
            'runs as cancelledExecutions'  => fn (Builder $q) => $q->where('Status', RunStatus::Cancelled),
            'runs as runningExecutions'    => fn (Builder $q) => $q->where('Status', RunStatus::Running),
        ])->with('latestRun')->find($id);

        if ($script === null) {
            throw ApiException::notFound("Script $id was not found.");
        }

        return response()->json(['data' => (new ScriptDetailResource($script))->resolve($request)]);
    }
}
