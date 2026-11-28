<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A registered script (table ScriptCatalog). A script has many runs.
 */
class Script extends Model
{
    protected $table = 'ScriptCatalog';
    protected $primaryKey = 'ScriptID';
    public $timestamps = false;              // CreatedDateUtc/ModifiedDateUtc are set by MySQL defaults
    protected $dateFormat = 'Y-m-d H:i:s.v'; // DATETIME(3) columns keep milliseconds
    protected $guarded = ['ScriptID'];

    protected $casts = [
        'CreatedDateUtc'  => 'datetime',
        'ModifiedDateUtc' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'ScriptID', 'ScriptID');
    }

    /**
     * The script's most recently started run. latestOfMany() finds it in SQL
     * (the run with the highest StartTimeUtc; ties go to the highest RunID),
     * so ->with('latestRun') loads it without fetching all the runs.
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Run::class, 'ScriptID', 'ScriptID')->latestOfMany('StartTimeUtc');
    }
}
