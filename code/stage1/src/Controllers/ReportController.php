<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET /api/v1/reports/execution-summary - totals by status, success rate, average duration
 * GET /api/v1/reports/failing-scripts   - scripts ranked by number of failed runs
 *
 * Both accept from/to (on run start time) and team filters.
 */
final class ReportController extends Controller
{
    /** @param array<string,string> $params */
    public function executionSummary(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        [$where, $args] = $this->filters($request->query, allowScriptId: true);

        $row = $this->query(
            "SELECT COUNT(*)                   AS total,
                    SUM(r.Status = 'Success')   AS success,
                    SUM(r.Status = 'Failed')    AS failed,
                    SUM(r.Status = 'Cancelled') AS cancelled,
                    SUM(r.Status = 'Running')   AS running,
                    AVG(CASE WHEN r.Status <> 'Running' THEN r.DurationMS END) AS avgDuration
             FROM ScriptRun r JOIN ScriptCatalog c ON c.ScriptID = r.ScriptID"
            . self::whereSql($where),
            $args
        )->fetch();

        $success = (int) $row['success'];
        $completed = $success + (int) $row['failed'] + (int) $row['cancelled'];

        return Response::json(200, ['data' => [
            'totalExecutions'      => (int) $row['total'],
            'successfulExecutions' => $success,
            'failedExecutions'     => (int) $row['failed'],
            'cancelledExecutions'  => (int) $row['cancelled'],
            'runningExecutions'    => (int) $row['running'],
            // Average over completed runs only; null when there are none.
            'averageDurationMs'    => $row['avgDuration'] === null ? null : (int) round((float) $row['avgDuration']),
            // successful / (successful + failed + cancelled); null when nothing has completed.
            'successRate'          => $completed === 0 ? null : round($success / $completed, 4),
        ]]);
    }

    /** @param array<string,string> $params */
    public function failingScripts(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        [$where, $args] = $this->filters($request->query, allowScriptId: false);
        $limit = isset($request->query['limit'])
            ? Validator::positiveInt($request->query['limit'], 'limit', 100)
            : 10;

        $rows = $this->query(
            "SELECT c.ScriptID, c.ScriptName, c.Team,
                    COUNT(*)                 AS total,
                    SUM(r.Status = 'Failed') AS failures,
                    MAX(CASE WHEN r.Status = 'Failed'
                             THEN COALESCE(r.EndTimeUtc, r.StartTimeUtc) END) AS lastFailure
             FROM ScriptRun r JOIN ScriptCatalog c ON c.ScriptID = r.ScriptID"
            . self::whereSql($where) .
            " GROUP BY c.ScriptID, c.ScriptName, c.Team
              HAVING failures > 0
              ORDER BY failures DESC, SUM(r.Status = 'Failed') / COUNT(*) DESC, c.ScriptName
              LIMIT ?",
            [...$args, $limit]
        )->fetchAll();

        return Response::json(200, ['data' => array_map(fn (array $row): array => [
            'scriptId'        => (int) $row['ScriptID'],
            'scriptName'      => $row['ScriptName'],
            'team'            => $row['Team'],
            'failureCount'    => (int) $row['failures'],
            'totalExecutions' => (int) $row['total'],
            'failureRate'     => round((int) $row['failures'] / (int) $row['total'], 4),
            'lastFailureUtc'  => Format::timestamp($row['lastFailure']),
        ], $rows)]);
    }

    /**
     * Builds WHERE conditions for the report filters.
     *
     * @param array<string,mixed> $q
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function filters(array $q, bool $allowScriptId): array
    {
        $where = [];
        $args = [];
        self::addDateRange($where, $args, 'r.StartTimeUtc', Validator::dateRange($q));
        if (isset($q['team'])) {
            $where[] = 'c.Team = ?';
            $args[] = Validator::optionalString($q, 'team', 100);
        }
        if ($allowScriptId && isset($q['scriptId'])) {
            $where[] = 'r.ScriptID = ?';
            $args[] = Validator::positiveInt($q['scriptId'], 'scriptId');
        }
        return [$where, $args];
    }
}
