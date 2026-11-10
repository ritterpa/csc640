<?php
/**
 * Front controller. NGINX sends every request that is not a real file here.
 * Routes are listed below; handlers live in src/Controllers.
 */
declare(strict_types=1);

// With PHP's built-in development server, let it serve the web pages
// (index.html, assets/) directly. NGINX does this itself with try_files.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(rtrim($file, '/') . '/index.html');
        return true;
    }
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require __DIR__ . '/../src/bootstrap.php';

use InfraLogging\ApiException;
use InfraLogging\App;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Router;
use InfraLogging\Controllers\AuthController;
use InfraLogging\Controllers\ScriptController;
use InfraLogging\Controllers\RunController;
use InfraLogging\Controllers\ExceptionController;
use InfraLogging\Controllers\MetricController;
use InfraLogging\Controllers\LogController;


$app = new App(dirname(__DIR__));
$router = new Router($app);

$router->add('POST',  '/api/v1/auth/login',                [AuthController::class, 'login']);
$router->add('GET',   '/api/v1/scripts',                   [ScriptController::class, 'index']);
$router->add('GET',   '/api/v1/scripts/{scriptId}',        [ScriptController::class, 'show']);
$router->add('GET',   '/api/v1/runs',                      [RunController::class, 'index']);
$router->add('POST',  '/api/v1/runs',                      [RunController::class, 'store']);
$router->add('GET',   '/api/v1/runs/{runId}',              [RunController::class, 'show']);
$router->add('PATCH', '/api/v1/runs/{runId}',              [RunController::class, 'update']);
$router->add('GET',   '/api/v1/runs/{runId}/exceptions',   [ExceptionController::class, 'index']);
$router->add('GET',   '/api/v1/runs/{runId}/metrics',      [MetricController::class, 'index']);
$router->add('GET',   '/api/v1/runs/{runId}/logs',         [LogController::class, 'index']);
$router->add('POST',  '/api/v1/runs/{runId}/logs',         [LogController::class, 'store']);
$router->add('GET',   '/api/v1/reports/execution-summary', [ReportController::class, 'executionSummary']);
$router->add('GET',   '/api/v1/reports/failing-scripts',   [ReportController::class, 'failingScripts']);


try {
    $response = $router->dispatch(Request::fromGlobals());
} catch (ApiException $e) {
    $response = Response::error($e->status, $e->errorCode, $e->getMessage(), $e->headers);
} catch (Throwable $e) {
    // Log the details for the operator; only show them to the client when debugging.
    error_log((string) $e);
    $message = $app->config->bool('APP_DEBUG') ? $e->getMessage() : 'An unexpected error occurred.';
    $response = Response::error(500, 'INTERNAL_ERROR', $message);
}

$response->send();
