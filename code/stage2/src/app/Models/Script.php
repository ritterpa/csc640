<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
