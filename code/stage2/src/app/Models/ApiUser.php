<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A login account (table ApiUser). Created out of band by
 * code/database/setup-database.sh; the API only reads it at login.
 */
class ApiUser extends Model
{
    protected $table = 'ApiUser';
    protected $primaryKey = 'UserID';
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $hidden = ['PasswordHash'];

    protected $casts = [
        'IsActive'       => 'boolean',
        'CreatedDateUtc' => 'datetime',
    ];
}
