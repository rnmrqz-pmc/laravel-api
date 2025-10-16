<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwoFactorModel extends Model
{
    protected $table = 'two_factor_auth';
    protected $primaryKey = 'ID'; 


    // If your table doesn't have timestamps
    public $timestamps = false;

    protected $fillable = [
        'ID',
        'userID',
        'uuid', 
        'user_agent', // if exists
        'user_ip',
        'created_on',
        'updated_on',
        'created_by',
        'updated_by'
    ];
}
