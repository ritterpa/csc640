<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ScriptDetailResource;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\ApiInput;
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

        // withRunCounts() counts the script's runs in the same query, in total and
        // per status; with('latestRun') loads the most recent run.
        // ScriptDetailResource formats both.
        $script = Script::query()->withRunCounts()->with('latestRun')->find($id);

        if ($script === null) {
            throw ApiException::notFound("Script $id was not found.");
        }

        return response()->json(['data' => (new ScriptDetailResource($script))->resolve($request)]);
    }
}
