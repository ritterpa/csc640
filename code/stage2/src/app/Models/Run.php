<?php

namespace App\Models;

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
