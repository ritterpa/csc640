<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET  /api/v1/runs/{runId}/logs  - log entries for a run (filters)
 * POST /api/v1/runs/{runId}/logs  - add a log entry (write scope)
 */
final class LogController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        $runId = Validator::guid($params['runId'], 'runId');

        $q = $request->query;
        $where = ['RunID = ?'];
        $args = [$runId];
        if (isset($q['level'])) {
            $where[] = 'Level = ?';
            $args[] = Validator::oneOf($q['level'], Validator::LOG_LEVELS, 'level');
        }
        if (isset($q['eventName'])) {
            $where[] = 'EventName = ?';
            $args[] = Validator::optionalString($q, 'eventName', 200);
        }
        self::addDateRange($where, $args, 'TimestampUtc', Validator::dateRange($q));

        $this->requireRun($runId);
        $rows = $this->query(
            'SELECT * FROM LogEntry' . self::whereSql($where) . ' ORDER BY TimestampUtc, LogID',
            $args
        )->fetchAll();

        return Response::json(200, ['data' => array_map([self::class, 'mapLog'], $rows)]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $this->authorize($request, 'write');
        $runId = Validator::guid($params['runId'], 'runId');

        $b = $request->json();
        $level = Validator::oneOf($b['level'] ?? null, Validator::LOG_LEVELS, 'level');
        $timestamp = isset($b['timestampUtc'])
            ? Validator::dateTime($b['timestampUtc'], 'timestampUtc')[0]
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $eventName    = Validator::optionalString($b, 'eventName', 200);
        $message      = Validator::optionalString($b, 'message');
        $category     = Validator::optionalString($b, 'category', 100);
        $functionName = Validator::optionalString($b, 'functionName', 255);
        $lineNumber   = Validator::optionalInt($b, 'lineNumber');
        $durationMs   = Validator::optionalInt($b, 'durationMs');
        $jsonData     = Validator::optionalJson($b, 'jsonData');

        $this->requireRun($runId);
        $this->call('usp_InfraLogging_WriteLog', [
            $runId, Format::dbTime($timestamp), $level, $eventName, $message,
            $category, $functionName, $lineNumber, $durationMs, $jsonData,
        ]);

        // LAST_INSERT_ID() keeps the value set by the INSERT inside the procedure.
        $logId = (int) $this->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        $row = $this->query('SELECT * FROM LogEntry WHERE LogID = ?', [$logId])->fetch();

        return Response::json(201, ['data' => self::mapLog($row)]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapLog(array $row): array
    {
        return [
            'logId'        => (int) $row['LogID'],
            'runId'        => $row['RunID'],
            'timestampUtc' => Format::timestamp($row['TimestampUtc']),
            'level'        => $row['Level'],
            'eventName'    => $row['EventName'],
            'message'      => $row['Message'],
            'category'     => $row['Category'],
            'functionName' => $row['FunctionName'],
            'lineNumber'   => Format::intOrNull($row['LineNumber']),
            'durationMs'   => Format::intOrNull($row['DurationMS']),
            'jsonData'     => Format::json($row['JsonData']),
        ];
    }
}
