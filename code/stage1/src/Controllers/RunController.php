<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use InfraLogging\ApiException;
use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET   /api/v1/runs          - run history (filters, pagination)
 * POST  /api/v1/runs          - start a run   (write scope)
 * GET   /api/v1/runs/{runId}  - one run
 * PATCH /api/v1/runs/{runId}  - finish/update (write scope)
 */
final class RunController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');

        $q = $request->query;
        $where = [];
        $args = [];
        if (isset($q['scriptId'])) {
            $where[] = 'ScriptID = ?';
            $args[] = Validator::positiveInt($q['scriptId'], 'scriptId');
        }
        if (isset($q['status'])) {
            $where[] = 'Status = ?';
            $args[] = Validator::oneOf($q['status'], Validator::RUN_STATUSES, 'status');
        }
        self::addDateRange($where, $args, 'StartTimeUtc', Validator::dateRange($q));
        $paging = Validator::paging($q);

        $whereSql = self::whereSql($where);
        $total = (int) $this->query("SELECT COUNT(*) FROM ScriptRun$whereSql", $args)->fetchColumn();
        $rows = $this->query(
            "SELECT * FROM ScriptRun$whereSql ORDER BY StartTimeUtc DESC, RunID LIMIT ? OFFSET ?",
            [...$args, $paging['limit'], ($paging['page'] - 1) * $paging['limit']]
        )->fetchAll();

        return Response::json(200, [
            'data'       => array_map([self::class, 'mapRunSummary'], $rows),
            'pagination' => ['page' => $paging['page'], 'limit' => $paging['limit'], 'total' => $total],
        ]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        $runId = Validator::guid($params['runId'], 'runId');

        return Response::json(200, ['data' => self::mapRunDetail($this->requireRun($runId))]);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $this->authorize($request, 'write');

        $b = $request->json();
        $scriptName = Validator::requiredString($b, 'scriptName', 200);
        if (!isset($b['startTimeUtc'])) {
            throw ApiException::badRequest('startTimeUtc is required.');
        }
        [$start] = Validator::dateTime($b['startTimeUtc'], 'startTimeUtc');
        $correlationId = isset($b['correlationId'])
            ? Validator::guid($b['correlationId'], 'correlationId')
            : Format::newGuid();
        $parentRunId = isset($b['parentRunId'])
            ? Validator::guid($b['parentRunId'], 'parentRunId')
            : null;

        $values = [
            'scriptVersion'     => Validator::optionalString($b, 'scriptVersion', 50),
            'description'       => Validator::optionalString($b, 'description'),
            'team'              => Validator::optionalString($b, 'team', 100),
            'repository'        => Validator::optionalString($b, 'repository', 500),
            'owner'             => Validator::optionalString($b, 'owner', 200),
            'computerName'      => Validator::optionalString($b, 'computerName', 255),
            'userName'          => Validator::optionalString($b, 'userName', 255),
            'powerShellVersion' => Validator::optionalString($b, 'powerShellVersion', 50),
            'psEdition'         => Validator::optionalString($b, 'psEdition', 50),
            'processId'         => Validator::optionalInt($b, 'processId'),
            'parameters'        => Validator::optionalJson($b, 'parameters'),
            'gitCommit'         => Validator::optionalString($b, 'gitCommit', 100),
            'scriptPath'        => Validator::optionalString($b, 'scriptPath', 1000),
        ];

        // A parent run that does not exist is bad input, not a missing resource.
        if ($parentRunId !== null
            && $this->query('SELECT 1 FROM ScriptRun WHERE RunID = ?', [$parentRunId])->fetch() === false) {
            throw ApiException::badRequest('parentRunId does not refer to an existing run.');
        }

        // The procedure registers the script in ScriptCatalog if it is new.
        $runId = Format::newGuid();
        $this->call('usp_InfraLogging_StartScriptRun', [
            $runId, $scriptName, $values['scriptVersion'], $values['description'],
            $values['team'], $values['repository'], $values['owner'],
            $values['computerName'], $values['userName'], Format::dbTime($start),
            $values['powerShellVersion'], $values['psEdition'], $values['processId'],
            $correlationId, $parentRunId, $values['parameters'],
            $values['gitCommit'], $values['scriptPath'],
        ]);

        $run = $this->requireRun($runId);
        return Response::json(201, ['data' => [
            'runId'         => $run['RunID'],
            'scriptId'      => (int) $run['ScriptID'],
            'scriptName'    => $run['ScriptName'],
            'startTimeUtc'  => Format::timestamp($run['StartTimeUtc']),
            'status'        => $run['Status'],
            'correlationId' => $run['CorrelationID'],
        ]], ['Location' => "/api/v1/runs/$runId"]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $this->authorize($request, 'write');
        $runId = Validator::guid($params['runId'], 'runId');

        $b = $request->json();
        $status = Validator::oneOf($b['status'] ?? null, Validator::FINAL_STATUSES, 'status');
        $end = isset($b['endTimeUtc'])
            ? Validator::dateTime($b['endTimeUtc'], 'endTimeUtc')[0]
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $message = Validator::optionalString($b, 'message');

        $run = $this->requireRun($runId);
        $start = new DateTimeImmutable($run['StartTimeUtc'], new DateTimeZone('UTC'));
        if ($end < $start) {
            throw ApiException::badRequest('endTimeUtc must not be before the run start time.');
        }
        // Duration in milliseconds, computed by the server from start and end.
        $durationMs = ((int) $end->format('U') - (int) $start->format('U')) * 1000
            + ((int) $end->format('v') - (int) $start->format('v'));

        $this->call('usp_InfraLogging_EndScriptRun', [
            $runId, Format::dbTime($end), $status, $durationMs, $message,
        ]);

        return Response::json(200, ['data' => self::mapRunDetail($this->requireRun($runId))]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapRunSummary(array $row): array
    {
        return [
            'runId'        => $row['RunID'],
            'scriptId'     => (int) $row['ScriptID'],
            'scriptName'   => $row['ScriptName'],
            'startTimeUtc' => Format::timestamp($row['StartTimeUtc']),
            'endTimeUtc'   => Format::timestamp($row['EndTimeUtc']),
            'status'       => $row['Status'],
            'durationMs'   => Format::intOrNull($row['DurationMS']),
            'computerName' => $row['ComputerName'],
            'userName'     => $row['UserName'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapRunDetail(array $row): array
    {
        return self::mapRunSummary($row) + [
            'scriptVersion'     => $row['ScriptVersion'],
            'description'       => $row['Description'],
            'powerShellVersion' => $row['PowerShellVersion'],
            'psEdition'         => $row['PSEdition'],
            'processId'         => Format::intOrNull($row['ProcessID']),
            'correlationId'     => $row['CorrelationID'],
            'parentRunId'       => $row['ParentRunID'],
            'parameters'        => Format::json($row['Parameters']),
            'gitCommit'         => $row['GitCommit'],
            'scriptPath'        => $row['ScriptPath'],
            'message'           => $row['Message'],
        ];
    }
}
