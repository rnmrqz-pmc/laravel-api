<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'trainers';
    protected $primaryKey = 'ID';
    public $timestamps = false; // You're using custom timestamp columns

    protected $fillable = [
        'employeeNo',
        'name',
        'team',
        'position',
        'email',
        'password',
        'admin',
        'manager',
        'supervisor',
        'trainer',
        'status',
        'with_2fa',
        'last_login',
        'last_ip',
        'created_by',
        'created_on',
        'updated_by',
        'updated_on'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'invalid_count',
        'is_locked',
        'locked_time',
        'last_login',
        'last_ip',
        'created_by',
        'created_on',
        'updated_by',
        'updated_on'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
