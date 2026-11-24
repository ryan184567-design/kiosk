<?php

/* *****************************************
File: GuestKioskLog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客機回報日誌 Model：每次心跳、故障回報各記一列（子站庫，固定使用 site_current 連線，只新增不修改）
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;

class GuestKioskLog extends Model
{
    public const TYPE_HEARTBEAT = 'heartbeat';
    public const TYPE_FAULT = 'fault';

    /** 只有 created_at，沒有 updated_at */
    public const UPDATED_AT = null;

    protected $connection = 'site_current';

    protected $table = 'guest_kiosk_logs';

    protected $fillable = ['app_id', 'log_type', 'fault_code', 'fault_status', 'payload', 'ip'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
