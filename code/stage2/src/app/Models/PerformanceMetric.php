<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A performance metric recorded during a run (table PerformanceMetric). Belongs to a run.
 */
class PerformanceMetric extends Model
{
    protected $table = 'PerformanceMetric';
    protected $primaryKey = 'MetricID';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $guarded = ['MetricID'];

    protected $casts = [
        'TimestampUtc' => 'datetime',
        'MetricValue'  => 'float',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'RunID', 'RunID');
    }
}
