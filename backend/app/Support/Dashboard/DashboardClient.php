<?php

/* *****************************************
File: DashboardClient.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 對儀錶板發出一次 HTTP POST 的共用方法：連線測試（同步）與上報重試任務（SendDashboardNotification）共用同一份送出邏輯
***************************************** */

namespace App\Support\Dashboard;

use Illuminate\Support\Facades\Http;

class DashboardClient
{
    /** 單次 POST 的逾時秒數：儀錶板慢或掛掉時不能拖住 nsst */
    public const TIMEOUT_SECONDS = 5;

    /** 回應內容最多保留的字數（記錄與連線測試回傳都用這個上限） */
    public const MAX_BODY_CHARS = 5000;

    /**
     * POST 一次，回傳結果；不丟例外（連不上、逾時都轉成 success=false）。
     *
     * @param  array<string, string>  $headers
     * @return array{success: bool, status_code: ?int, duration_ms: int, tested_at: string, error: ?string, response_body: ?string}
     */
    public static function post(string $url, array $headers, array $payload): array
    {
        $startedAt = microtime(true);
        $result = ['success' => false, 'status_code' => null, 'duration_ms' => 0, 'tested_at' => now()->format('Y-m-d H:i:s'), 'error' => null, 'response_body' => null];

        try {
            $response = Http::withHeaders($headers)->acceptJson()->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($url, $payload);

            $result['status_code'] = $response->status();
            $result['success'] = $response->successful();
            $result['response_body'] = mb_substr($response->body(), 0, self::MAX_BODY_CHARS);
            $result['error'] = $result['success'] ? null : 'HTTP '.$response->status();
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $result['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $result;
    }
}
