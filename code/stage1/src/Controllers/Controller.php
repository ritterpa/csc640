<?php
declare(strict_types=1);

namespace InfraLogging\Controllers;

use InfraLogging\ApiException;
use InfraLogging\App;
use InfraLogging\Format;
use InfraLogging\Request;
use PDO;
use PDOStatement;

/**
 * Helpers shared by all controllers: authorization, prepared queries,
 * run lookup, and date-range filters.
 */
abstract class Controller
{
    public function __construct(protected readonly App $app) {}

    /**
     * Requires a valid bearer token with the given scope ('read' or 'write').
     * Called first in every protected action, before any input validation.
     *
     * @return array<string,mixed>
     */
    protected function authorize(Request $request, string $scope): array
    {
        return $this->app->auth()->requireScope($request, $scope);
    }

    /**
     * Runs a prepared statement with positional parameters. Integers are
     * bound as integers (required for LIMIT/OFFSET), nulls as NULL.
     *
     * @param list<mixed> $params
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->app->pdo()->prepare($sql);
        foreach ($params as $i => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Calls a stored procedure and discards its result sets.
     *
     * @param list<mixed> $params
     */
    protected function call(string $procedure, array $params): void
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        $this->query("CALL $procedure($placeholders)", $params)->closeCursor();
    }

    /**
     * Returns the ScriptRun row for a run ID, or throws 404.
     *
     * @return array<string,mixed>
     */
    protected function requireRun(string $runId): array
    {
        $row = $this->query('SELECT * FROM ScriptRun WHERE RunID = ?', [$runId])->fetch();
        if ($row === false) {
            throw ApiException::notFound("Run $runId was not found.");
        }
        return $row;
    }

    /**
     * Adds from/to conditions (from Validator::dateRange) on a DATETIME column.
     *
     * @param list<string> $where
     * @param list<mixed>  $params
     * @param array{from: ?\DateTimeImmutable, toExclusive: ?\DateTimeImmutable, toInclusive: ?\DateTimeImmutable} $range
     */
    protected static function addDateRange(array &$where, array &$params, string $column, array $range): void
    {
        if ($range['from'] !== null) {
            $where[] = "$column >= ?";
            $params[] = Format::dbTime($range['from']);
        }
        if ($range['toExclusive'] !== null) {
            $where[] = "$column < ?";
            $params[] = Format::dbTime($range['toExclusive']);
        }
        if ($range['toInclusive'] !== null) {
            $where[] = "$column <= ?";
            $params[] = Format::dbTime($range['toInclusive']);
        }
    }

    /** @param list<string> $where */
    protected static function whereSql(array $where): string
    {
        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }
}
