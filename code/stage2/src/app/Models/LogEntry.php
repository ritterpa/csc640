<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured log event recorded during a run (table LogEntry). Belongs to a run.
 */
class LogEntry extends Model
{
    protected $table = 'LogEntry';
    protected $primaryKey = 'LogID';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $guarded = ['LogID'];

    protected $casts = [
        'TimestampUtc' => 'datetime',
        'LineNumber'   => 'integer',
        'DurationMS'   => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'RunID', 'RunID');
    }
}
