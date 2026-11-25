<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\MetricResource;
use App\Models\Run;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/runs/{runId}/metrics - performance metrics recorded during a run.
 */
class MetricController extends Controller
{
    public function index(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $run = Run::find($id) ?? throw ApiException::notFound("Run $id was not found.");

        $metrics = $run->metrics()->orderBy('TimestampUtc')->orderBy('MetricID')->get();

        return response()->json(['data' => MetricResource::collection($metrics)->resolve($request)]);
    }
}
