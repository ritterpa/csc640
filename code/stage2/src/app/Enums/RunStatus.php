<?php

namespace App\Enums;

/**
 * The status of a script run (column ScriptRun.Status). The values are the
 * text stored in the database and used in the API; they match the CHECK
 * constraint in code/database/Deploy-InfraLogging.mysql.sql.
 *
 * Laravel binds a backed enum as its value, so a case can be passed straight
 * to the query builder: ->where('Status', RunStatus::Failed).
 */
enum RunStatus: string
{
    case Running = 'Running';
    case Success = 'Success';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';

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
}
