<?php

/* *****************************************
File: DashboardReportLogger.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-24
Description: 把一次對儀錶板的送出結果寫進 dashboard_report_logs（送出內容＋對方回應）；寫入失敗只記 log，不影響上報本身
***************************************** */

namespace App\Support\Dashboard;

use App\Api\Guest\Models\DashboardReportLog;
use App\Api\Guest\Services\DashboardReportService;
use Illuminate\Support\Facades\Log;

class DashboardReportLogger
{
    /**
     * @param  array  $result  DashboardClient::post 的回傳
     * @param  array  $payload  送出的 POST 內容
     * @param  array<int, string>  $maskKeys  payload 最上層要遮罩的欄位名稱（放在 body 的簽名／固定資料）
     */
    public static function record(array $result, string $url, array $payload, int $attempt = 1, bool $isTest = false, array $maskKeys = []): void
    {
        try {
            foreach ($maskKeys as $key) {
                if (array_key_exists($key, $payload)) {
                    $payload[$key] = DashboardReportService::maskSecret((string) $payload[$key]);
                }
            }

            DashboardReportLog::query()->create([
                'notify_type' => (string) ($payload['notify_type'] ?? ''),
                'message_id' => $payload['message_id'] ?? null,
                'attempt' => $attempt,
                'is_test' => $isTest,
                'url' => $url,
                'success' => $result['success'],
                'status_code' => $result['status_code'],
                'duration_ms' => $result['duration_ms'],
                'request_body' => $payload,
                'response_body' => $result['response_body'] ?? null,
                'error' => $result['error'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('儀錶板上報記錄寫入失敗', ['error' => $e->getMessage()]);
        }
    }
}
