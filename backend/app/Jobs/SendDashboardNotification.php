<?php

/* *****************************************
File: SendDashboardNotification.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 把一則通知 POST 到儀錶板並記錄每次的送出與回應，帶重試：單次最多等 5 秒，失敗 10 秒後重試，共 3 次都失敗就丟棄
***************************************** */

namespace App\Jobs;

use App\Support\Dashboard\DashboardClient;
use App\Support\Dashboard\DashboardReportLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// 重試規則（docs/DASHBOARD_REPORT.MD 第 3 節）：
//   第 1 次送出失敗（逾時 5 秒、連不上、對方回非 2xx）→ 10 秒後第 2 次 → 再失敗 10 秒後第 3 次 → 仍失敗就丟棄（記 log，不進 failed_jobs）。
//
// 任務建立時就把「網址、標頭（含簽名）、通知內容」全部帶進來，執行時不再讀設定也不需要子站連線：
// 多站模式下 queue worker 是共用的一個程序，不會知道這則通知屬於哪個子站。
// 因簽名在佇列資料表裡，實作 ShouldBeEncrypted 讓整個任務加密儲存。
//
// 需要有 `php artisan queue:work` 常駐（QUEUE_CONNECTION=database）；QUEUE_CONNECTION=sync 時沒有延遲重試。
class SendDashboardNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 最多嘗試次數（含第一次）：3 次都失敗就丟棄 */
    public int $tries = 3;

    /** 每次失敗後隔幾秒重試 */
    public int $backoff = 10;

    /** worker 執行單次任務的上限秒數（HTTP 逾時 5 秒，留一點餘裕） */
    public int $timeout = 15;

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $maskKeys  記錄時要遮罩的 payload 欄位（放在 body 的簽名／固定資料）
     * @param  array  $siteConnection  建立任務時的子站資料庫連線設定；worker 是共用程序，靠它把記錄寫回正確的子站
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly array $payload,
        public readonly array $maskKeys = [],
        public readonly array $siteConnection = [],
    ) {}

    public function handle(): void
    {
        $this->bindSiteConnection();

        $result = DashboardClient::post($this->url, $this->headers, $this->payload);
        DashboardReportLogger::record($result, $this->url, $this->payload, $this->attempts(), false, $this->maskKeys);

        if ($result['success']) {
            return;
        }

        $context = [
            'notify_type' => $this->payload['notify_type'] ?? null,
            'message_id' => $this->payload['message_id'] ?? null,
            'attempt' => $this->attempts(),
            'status' => $result['status_code'],
            'error' => $result['error'],
        ];

        if ($this->attempts() >= $this->tries) {
            // 用盡次數：直接結束（不再丟例外），任務就此丟棄，不會留在 failed_jobs
            Log::error('儀錶板上報失敗，已達重試上限，丟棄', $context);

            return;
        }

        Log::warning('儀錶板上報失敗，10 秒後重試', $context);

        throw new RuntimeException('儀錶板上報失敗：'.$result['error']);
    }

    /** worker 共用一個程序，每個任務執行前先指向它所屬的子站庫（連線設定沒變就不動，避免白白斷線） */
    private function bindSiteConnection(): void
    {
        if ($this->siteConnection === [] || config('database.connections.site_current') == $this->siteConnection) {
            return;
        }

        Config::set('database.connections.site_current', $this->siteConnection);
        DB::purge('site_current');
    }
}
