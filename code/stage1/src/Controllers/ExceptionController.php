<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET /api/v1/runs/{runId}/exceptions - exceptions captured during a run.
 * A run with no exceptions returns an empty array; an unknown run is 404.
 */
final class ExceptionController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        $runId = Validator::guid($params['runId'], 'runId');

        $this->requireRun($runId);
        $rows = $this->query(
            'SELECT * FROM ExceptionLog WHERE RunID = ? ORDER BY TimestampUtc, ExceptionID',
            [$runId]
        )->fetchAll();

        return Response::json(200, ['data' => array_map(fn (array $row): array => [
            'exceptionId'           => (int) $row['ExceptionID'],
            'runId'                 => $row['RunID'],
            'timestampUtc'          => Format::timestamp($row['TimestampUtc']),
            'exceptionType'         => $row['ExceptionType'],
            'message'               => $row['Message'],
            'stackTrace'            => $row['StackTrace'],
            'scriptStackTrace'      => $row['ScriptStackTrace'],
            'fullyQualifiedErrorId' => $row['FullyQualifiedErrorId'],
            'category'              => $row['Category'],
            'invocationInfo'        => Format::json($row['InvocationInfo']),
            'jsonData'              => Format::json($row['JsonData']),
        ], $rows)]);
    }
}
