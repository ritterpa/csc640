<?php

namespace App\Enums;

/**
 * The severity of a log entry (column LogEntry.Level), lowest first. The
 * values are the text stored in the database and used in the API; they match
 * the CHECK constraint in code/database/Deploy-InfraLogging.mysql.sql.
 */
enum LogLevel: string
{
    case Trace = 'Trace';
    case Debug = 'Debug';
    case Information = 'Information';
    case Warning = 'Warning';
    case Error = 'Error';
    case Critical = 'Critical';
}
