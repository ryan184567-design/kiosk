<?php

/* *****************************************
File: DashboardReportLog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-24
Description: 儀錶板上報記錄 Model：每次實際送出（含重試、連線測試）一列，記下送出內容與對方回應，子站庫，固定使用 site_current 連線
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardReportLog extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'site_current';

    protected $table = 'dashboard_report_logs';

    protected $guarded = [];

    protected $casts = [
        'is_test' => 'boolean',
        'success' => 'boolean',
        'request_body' => 'array',
        'attempt' => 'integer',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];
}
