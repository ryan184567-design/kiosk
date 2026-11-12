<?php

/* *****************************************
File: AuditLog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站自己的審計日誌，見 README 15 節。
***************************************** */

namespace App\Support\Audit\Models;

use Illuminate\Database\Eloquent\Model;

// 子站自己的審計日誌，見 README 15 節。
class AuditLog extends Model
{
    protected $connection = 'site_current';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'target',
        'context',
        'ip',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
