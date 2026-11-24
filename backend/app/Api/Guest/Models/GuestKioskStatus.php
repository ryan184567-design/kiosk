<?php

/* *****************************************
File: GuestKioskStatus.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客機最後回報狀態 Model：每台訪客機一列，記錄最後回報時間與目前未恢復的故障（子站庫，固定使用 site_current 連線）
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;

class GuestKioskStatus extends Model
{
    /** 超過幾秒沒回報就視為離線（訪客機每 30 秒回報一次，容許漏一次） */
    public const OFFLINE_AFTER_SECONDS = 60;

    /** 故障代碼 */
    public const FAULT_PRINTER_PAPER_OUT = 'PRINTER_PAPER_OUT';
    public const FAULT_PRINTER_DISCONNECTED = 'PRINTER_DISCONNECTED';
    public const FAULT_ID_SCANNER_DISCONNECTED = 'ID_SCANNER_DISCONNECTED';
    /** 「系統正常」：不是故障，代表這台訪客機目前所有故障都已排除 */
    public const SYSTEM_OK = 'SYSTEM_OK';

    public const FAULT_CODES = [
        self::FAULT_PRINTER_PAPER_OUT,
        self::FAULT_PRINTER_DISCONNECTED,
        self::FAULT_ID_SCANNER_DISCONNECTED,
    ];

    protected $connection = 'site_current';

    protected $table = 'guest_kiosk_statuses';

    protected $fillable = ['app_id', 'last_reported_at', 'faults'];

    protected $casts = [
        'last_reported_at' => 'datetime',
        'faults' => 'array',
    ];

    /** 最後一次回報在 1 分鐘內才算在線；從未回報過視為離線 */
    public function isOnline(): bool
    {
        return $this->last_reported_at !== null
            && $this->last_reported_at->gte(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }
}
