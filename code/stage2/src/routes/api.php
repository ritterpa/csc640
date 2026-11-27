<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExceptionController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MetricController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ScriptController;
use Illuminate\Support\Facades\Route;

// Every path here starts with /api (added by bootstrap/app.php), so these
// are the same URLs as Stage 1, for example /api/v1/runs.

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    // Read endpoints: a valid bearer token (401 otherwise) with the read scope (403 otherwise).
    Route::middleware(['auth:jwt', 'scope:read'])->group(function () {
        Route::get('scripts', [ScriptController::class, 'index']);
        Route::get('scripts/{scriptId}', [ScriptController::class, 'show']);
        Route::get('runs', [RunController::class, 'index']);
        Route::get('runs/{runId}', [RunController::class, 'show']);
        Route::get('runs/{runId}/logs', [LogController::class, 'index']);
        Route::get('runs/{runId}/exceptions', [ExceptionController::class, 'index']);
        Route::get('runs/{runId}/metrics', [MetricController::class, 'index']);
    });

    // Write endpoints: a valid bearer token with the write scope (403 for a read-only token).
    Route::middleware(['auth:jwt', 'scope:write'])->group(function () {
        Route::post('runs', [RunController::class, 'store']);
        Route::patch('runs/{runId}', [RunController::class, 'update']);
        Route::post('runs/{runId}/logs', [LogController::class, 'store']);
    });

    // Commit 3 adds the reports.
});
