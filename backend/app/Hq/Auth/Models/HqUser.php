<?php

/* *****************************************
File: HqUser.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站自己的使用者，見 README 6.12。
***************************************** */

namespace App\Hq\Auth\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// 主站自己的使用者，見 README 6.12。精簡設計，不套用子站那套身份別/端口/角色模型。
class HqUser extends Authenticatable
{
    use Notifiable;

    // 明確寫死連線，見 App\Hq\Models\Module 的註解說明。
    protected $connection = 'mysql';

    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'is_super_admin',
        'is_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
