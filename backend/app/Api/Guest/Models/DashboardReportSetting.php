<?php

/* *****************************************
File: DashboardReportSetting.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 儀錶板上報設定 Model：每個子站一列（網址、是否啟用、上傳內容、自訂簽名／固定資料多組），子站庫，固定使用 site_current 連線
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardReportSetting extends Model
{
    /** 上傳內容（contents）：值 => 對應的 notify_type 清單，格式見 docs/DASHBOARD_REPORT.MD */
    public const CONTENT_NOTIFY_TYPES = [
        'heartbeat' => ['heartbeat', 'device_fault'],
        'approved' => ['reservation_approved'],
        'modified' => ['reservation_modified'],
        'cancelled' => ['reservation_cancelled'],
        'checkin' => ['reservation_checkin'],
        'checkout' => ['reservation_checkout'],
    ];

    protected $connection = 'site_current';

    protected $table = 'dashboard_report_settings';

    protected $fillable = [
        'is_enabled', 'url', 'upload_method', 'format', 'contents', 'schedule',
        'auth_items',
    ];

    // 簽名值絕不輸出明文
    protected $hidden = ['auth_items'];

    // auth_items：[{name, location: header|body, value, bearer}]，整串加密儲存
    protected $casts = [
        'is_enabled' => 'boolean',
        'contents' => 'array',
        'auth_items' => 'encrypted:array',
    ];

    /** 這個通知類型（notify_type）目前是否要上報：已啟用、有網址，且上傳內容勾選了它 */
    public function shouldReport(string $notifyType): bool
    {
        if (! $this->is_enabled || ! $this->url) {
            return false;
        }

        foreach ((array) $this->contents as $content) {
            if (in_array($notifyType, self::CONTENT_NOTIFY_TYPES[$content] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
