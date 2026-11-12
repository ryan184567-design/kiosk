<?php

/* *****************************************
File: HqAuditLog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站自己的審計日誌，見 README 15 節。
***************************************** */

namespace App\Support\Audit\Models;

use Illuminate\Database\Eloquent\Model;

// 主站自己的審計日誌，見 README 15 節。跟子站的 AuditLog（同目錄）是兩張
// 不同資料庫的表，類別故意分開命名，不共用同一個類別名稱。
class HqAuditLog extends Model
{
    // 明確寫死連線，見 Module 的註解說明。
    protected $connection = 'mysql';

    // 明確寫死資料表名稱：類別改名為 HqAuditLog 之後，Eloquent 預設命名慣例
    // 會猜成 hq_audit_logs，但實際資料表仍是 audit_logs（見 database/hq/
    // migrations 的 create_audit_logs_table），改類別名稱不代表要跟著改表名，
    // 已實測踩過這個坑（改名當下忘記補這行，寫入時才發現資料表不存在）。
    protected $table = 'audit_logs';

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
