<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Resources\ExceptionLogResource;
use App\Models\Run;
use App\Support\ApiInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/runs/{runId}/exceptions - exceptions captured during a run.
 * A run with none returns an empty array; an unknown run is a 404.
 */
class ExceptionController extends Controller
{
    public function index(Request $request, string $runId): JsonResponse
    {
        $id = ApiInput::guid($runId, 'runId');
        $run = Run::find($id) ?? throw ApiException::notFound("Run $id was not found.");

        $exceptions = $run->exceptionLogs()->orderBy('TimestampUtc')->orderBy('ExceptionID')->get();

        return response()->json(['data' => ExceptionLogResource::collection($exceptions)->resolve($request)]);
    }
}
