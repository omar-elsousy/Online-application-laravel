<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'oracle_sales';
    protected $table = 'online_app_users';

    protected $fillable = [
        'mobile',
        'password',
        'selected_pos_code',
    ];

    protected $hidden = [
        'password',
    ];
}
