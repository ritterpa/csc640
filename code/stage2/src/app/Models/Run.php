<?php

namespace App\Models;

use App\Support\DateInput;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a script (table ScriptRun). The primary key is a GUID
 * string. A run belongs to a script, may have a parent run, and has many
 * log entries, exceptions, and metrics.
 */
class Run extends Model
{
    protected $table = 'ScriptRun';
    protected $primaryKey = 'RunID';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $guarded = [];

    protected $casts = [
        'StartTimeUtc' => 'datetime',
        'EndTimeUtc'   => 'datetime',
        'DurationMS'   => 'integer',
        'ProcessID'    => 'integer',
        'ScriptID'     => 'integer',
    ];

    /**
     * Records the end of the run: end time, final status, message, and the
     * duration, which the server always computes (like Stage 1's stored
     * procedure usp_InfraLogging_EndScriptRun). The caller checks that $end
     * is not before the start time.
     */
    public function finish(DateTimeImmutable $end, string $status, ?string $message): void
    {
        $start = DateTimeImmutable::createFromInterface($this->StartTimeUtc);

        $this->update([
            'EndTimeUtc' => DateInput::db($end),
            'Status'     => $status,
            // Milliseconds: "Uv" is Unix seconds followed by milliseconds.
            'DurationMS' => (int) $end->format('Uv') - (int) $start->format('Uv'),
            'Message'    => $message,
        ]);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'ScriptID', 'ScriptID');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'ParentRunID', 'RunID');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Run::class, 'ParentRunID', 'RunID');
    }

    public function logEntries(): HasMany
    {
        return $this->hasMany(LogEntry::class, 'RunID', 'RunID');
    }

    public function exceptionLogs(): HasMany
    {
        return $this->hasMany(ExceptionLog::class, 'RunID', 'RunID');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PerformanceMetric::class, 'RunID', 'RunID');
    }
}
