<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exception captured during a run (table ExceptionLog). Belongs to a run.
 */
class ExceptionLog extends Model
{
    protected $table = 'ExceptionLog';
    protected $primaryKey = 'ExceptionID';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $guarded = ['ExceptionID'];

    protected $casts = [
        'TimestampUtc' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'RunID', 'RunID');
    }
}
