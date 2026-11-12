<?php

/* *****************************************
File: UserJwtSession.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: JWT 會話，見 README 11.1。
***************************************** */

namespace App\Api\Auth\Models;

use Illuminate\Database\Eloquent\Model;

// JWT 會話，見 README 11.1。單帳號單 Token 的範疇是 (user_id, portal_id)。
class UserJwtSession extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'user_id',
        'portal_id',
        'role_id',
        'jti',
        'issued_via',
        'last_access_at',
        'last_access_ip',
        'last_access_user_agent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_access_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
