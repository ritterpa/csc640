<?php

namespace App\Enums;

/**
 * The status of a script run (column ScriptRun.Status). The values are the
 * text stored in the database and used in the API; they match the CHECK
 * constraint in code/database/Deploy-InfraLogging.mysql.sql.
 *
 * This is the one list of statuses: the per-status counts (script details,
 * execution summary) are built from cases(), so adding a status here (and to
 * the CHECK constraint) is enough. The cases are in the order their count
 * fields appear in API responses.
 *
 * Laravel binds a backed enum as its value, so a case can be passed straight
 * to the query builder: ->where('Status', RunStatus::Failed).
 */
enum RunStatus: string
{
    case Success = 'Success';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';
    case Running = 'Running';

    /** Whether the run has ended: every status except Running. */
    public function isFinal(): bool
    {
        return $this !== self::Running;
    }

    /** @return list<self> the statuses a finished run can have */
    public static function finals(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isFinal()));
    }

    /** The API field that counts runs with this status, as in Stage 1. */
    public function countField(): string
    {
        return match ($this) {
            self::Success   => 'successfulExecutions',
            self::Failed    => 'failedExecutions',
            self::Cancelled => 'cancelledExecutions',
            self::Running   => 'runningExecutions',
        };
    }
}
