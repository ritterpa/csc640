<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\ApiException;
use InfraLogging\Format;
use InfraLogging\Request;
use InfraLogging\Response;
use InfraLogging\Validator;

/**
 * GET /api/v1/scripts             - script catalog
 * GET /api/v1/scripts/{scriptId}  - one script with run counts and latest run
 */
final class ScriptController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');

        $rows = $this->query('SELECT * FROM ScriptCatalog ORDER BY ScriptID')->fetchAll();
        return Response::json(200, ['data' => array_map([self::class, 'mapScript'], $rows)]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->authorize($request, 'read');
        $scriptId = Validator::positiveInt($params['scriptId'], 'scriptId');

        $script = $this->query('SELECT * FROM ScriptCatalog WHERE ScriptID = ?', [$scriptId])->fetch();
        if ($script === false) {
            throw ApiException::notFound("Script $scriptId was not found.");
        }

        $counts = $this->query(
            "SELECT COUNT(*)                 AS total,
                    SUM(Status = 'Success')   AS success,
                    SUM(Status = 'Failed')    AS failed,
                    SUM(Status = 'Cancelled') AS cancelled,
                    SUM(Status = 'Running')   AS running
             FROM ScriptRun WHERE ScriptID = ?",
            [$scriptId]
        )->fetch();

        $latest = $this->query(
            'SELECT RunID, StartTimeUtc, EndTimeUtc, Status
             FROM ScriptRun WHERE ScriptID = ? ORDER BY StartTimeUtc DESC LIMIT 1',
            [$scriptId]
        )->fetch();

        $data = self::mapScript($script) + [
            'totalExecutions'      => (int) $counts['total'],
            'successfulExecutions' => (int) $counts['success'],
            'failedExecutions'     => (int) $counts['failed'],
            'cancelledExecutions'  => (int) $counts['cancelled'],
            'runningExecutions'    => (int) $counts['running'],
            'latestRun'            => $latest === false ? null : [
                'runId'        => $latest['RunID'],
                'startTimeUtc' => Format::timestamp($latest['StartTimeUtc']),
                'endTimeUtc'   => Format::timestamp($latest['EndTimeUtc']),
                'status'       => $latest['Status'],
            ],
        ];
        return Response::json(200, ['data' => $data]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapScript(array $row): array
    {
        return [
            'scriptId'        => (int) $row['ScriptID'],
            'scriptName'      => $row['ScriptName'],
            'description'     => $row['Description'],
            'team'            => $row['Team'],
            'owner'           => $row['Owner'],
            'repository'      => $row['Repository'],
            'createdDateUtc'  => Format::timestamp($row['CreatedDateUtc']),
            'modifiedDateUtc' => Format::timestamp($row['ModifiedDateUtc']),
        ];
    }
}
