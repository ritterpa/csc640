<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET /api/v1/runs/{runId}/metrics - performance metrics recorded during a run.
 */
final class MetricController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        $runId = Validator::guid($params['runId'], 'runId');

        $this->requireRun($runId);
        $rows = $this->query(
            'SELECT * FROM PerformanceMetric WHERE RunID = ? ORDER BY TimestampUtc, MetricID',
            [$runId]
        )->fetchAll();

        return Response::json(200, ['data' => array_map(fn (array $row): array => [
            'metricId'     => (int) $row['MetricID'],
            'runId'        => $row['RunID'],
            'timestampUtc' => Format::timestamp($row['TimestampUtc']),
            'metricName'   => $row['MetricName'],
            // DECIMAL(38,10) arrives as a string; the API returns a JSON number.
            'metricValue'  => (float) $row['MetricValue'],
            'unit'         => $row['Unit'],
            'category'     => $row['Category'],
            'jsonData'     => Format::json($row['JsonData']),
        ], $rows)]);
    }
}
