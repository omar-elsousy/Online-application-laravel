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

    public function tokenContextValue(string $key): ?string
    {
        $token = $this->currentAccessToken();
        $prefix = $key . ':';

        foreach (($token?->abilities ?? []) as $ability) {
            if (is_string($ability) && str_starts_with($ability, $prefix)) {
                return substr($ability, strlen($prefix));
            }
        }

        return null;
    }
}
