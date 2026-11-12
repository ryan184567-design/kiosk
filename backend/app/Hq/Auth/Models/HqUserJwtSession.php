<?php

/* *****************************************
File: HqUserJwtSession.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站 JWT 會話紀錄，見 README 6.12、11.1。
***************************************** */

namespace App\Hq\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class HqUserJwtSession extends Model
{
    // 明確寫死連線，見 App\Hq\Models\Module 的註解說明。
    protected $connection = 'mysql';

    protected $fillable = [
        'hq_user_id',
        'jti',
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

    public function hqUser()
    {
        return $this->belongsTo(HqUser::class);
    }
}
