<?php

/* *****************************************
File: DashboardReportService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 儀錶板上報 Service：讀寫上報設定、組出各種通知的內容、交給佇列任務 POST 到儀錶板（失敗重試）。
通知格式見 docs/DASHBOARD_REPORT.MD；業務程式不要直接呼叫這裡，改呼叫 App\Support\Dashboard\DashboardReporter。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\DashboardReportSetting;
use App\Api\Guest\Models\DashboardReportLog;
use App\Api\Guest\Models\GuestApply;
use App\Jobs\SendDashboardNotification;
use App\Support\Dashboard\DashboardClient;
use App\Support\Dashboard\DashboardReportLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DashboardReportService
{
    /** 預約類通知：DashboardReporter 的方法名 => notify_type */
    public const RESERVATION_NOTIFY_TYPES = [
        'approved' => 'reservation_approved',
        'modified' => 'reservation_modified',
        'cancelled' => 'reservation_cancelled',
        'checkin' => 'reservation_checkin',
        'checkout' => 'reservation_checkout',
    ];

    /** 放進 POST 內容的固定資料不可使用的名稱：通知信封本身的欄位 */
    public const RESERVED_BODY_KEYS = ['notify_type', 'message_id', 'sent_at'];

    public function __construct(
        private readonly GuestKioskService $kiosks,
        private readonly GuestReasonDictionary $reasons,
    ) {}

    /** 取得設定；這個子站還沒有設定時建立一筆預設值（未啟用） */
    public function settings(): DashboardReportSetting
    {
        return DashboardReportSetting::query()->firstOrCreate([], [
            'is_enabled' => false,
            'upload_method' => 'api',
            'format' => 'json',
            'contents' => array_keys(DashboardReportSetting::CONTENT_NOTIFY_TYPES),
            'schedule' => 'realtime',
            'auth_items' => [],
        ]);
    }

    /**
     * 更新設定；$data 沒有的欄位沿用原值。
     * auth_items 有帶就整串取代；其中某一組沒帶 value，沿用原本「同名稱＋同位置」那組的值（介面不回傳明文，所以改別的欄位時不必重送）。
     */
    public function updateSettings(array $data): DashboardReportSetting
    {
        $setting = $this->settings();

        if (array_key_exists('auth_items', $data)) {
            $data['auth_items'] = $this->mergeAuthItems((array) $data['auth_items'], (array) $setting->auth_items);
        }

        $setting->fill($data)->save();

        return $setting;
    }

    /**
     * 整理自訂簽名／固定資料清單：補上沿用的值、檢查重複與保留欄位。
     *
     * @param  array<int, array{name: string, location?: string, value?: ?string, bearer?: ?bool}>  $items
     * @param  array<int, array>  $old  目前已儲存的清單
     * @return array<int, array{name: string, location: string, value: string, bearer: bool}>
     */
    private function mergeAuthItems(array $items, array $old): array
    {
        $oldValues = [];
        foreach ($old as $o) {
            $oldValues[($o['location'] ?? 'header').'|'.strtolower($o['name'])] = $o['value'] ?? null;
        }

        $result = [];
        $seen = [];
        foreach (array_values($items) as $i => $item) {
            $location = $item['location'] ?? 'header';
            $key = $location.'|'.strtolower($item['name']);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["auth_items.$i.name" => __('api.dashboard_report.auth_duplicate')]);
            }
            if ($location === 'body' && in_array($item['name'], self::RESERVED_BODY_KEYS, true)) {
                throw ValidationException::withMessages(["auth_items.$i.name" => __('api.dashboard_report.auth_reserved')]);
            }

            $value = $item['value'] ?? ($oldValues[$key] ?? null);
            if ($value === null || $value === '') {
                throw ValidationException::withMessages(["auth_items.$i.value" => __('api.dashboard_report.auth_value_required')]);
            }

            $seen[$key] = true;
            $result[] = [
                'name' => $item['name'],
                'location' => $location,
                'value' => (string) $value,
                'bearer' => $location === 'header' && ! empty($item['bearer']),
            ];
        }

        return $result;
    }

    /** 上報記錄查詢（新的在前） */
    public function logs(?string $notifyType, ?bool $success, ?bool $isTest, ?string $dateFrom, ?string $dateTo): \Illuminate\Database\Eloquent\Builder
    {
        return DashboardReportLog::query()
            ->when($notifyType, fn ($q, $v) => $q->where('notify_type', $v))
            ->when($success !== null, fn ($q) => $q->where('success', $success))
            ->when($isTest !== null, fn ($q) => $q->where('is_test', $isTest))
            ->when($dateFrom, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($dateTo, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('id');
    }

    /** 簽名值遮罩：只露出最後 4 碼 */
    public static function maskSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('*', 8).mb_substr($value, -4);
    }

    /**
     * 上報預約通知。
     *
     * @param  string  $event  approved／modified／cancelled／checkin／checkout
     */
    public function reportReservation(string $event, GuestApply $apply): bool
    {
        $notifyType = self::RESERVATION_NOTIFY_TYPES[$event];

        return $this->send($notifyType, $this->reservationPayload($apply));
    }

    /** 上報訪客機故障（發生、恢復、系統正常），算在上傳內容「心跳」底下 */
    public function reportDeviceFault(string $kioskId, string $faultCode, string $faultStatus, string $faultMessage, string $occurredAt): bool
    {
        return $this->send('device_fault', [
            'kiosk_id' => $kioskId,
            'fault_code' => $faultCode,
            'fault_status' => $faultStatus,
            'fault_message' => $faultMessage,
            'occurred_at' => $occurredAt,
        ]);
    }

    /** 上報心跳：nsst 本身存活，加上各訪客機最後回報時間與故障（由排程每分鐘呼叫） */
    public function reportHeartbeat(): bool
    {
        return $this->send('heartbeat', [
            'nsst' => ['status' => 'online'],
            'kiosks' => $this->kiosks->snapshot(),
        ]);
    }

    /**
     * 組出預約通知的內容（欄位見 docs/DASHBOARD_REPORT.MD 第 6 節）。
     * 這裡取的是呼叫當下資料庫裡的最新資料；呼叫端請在交易提交後再呼叫。
     */
    public function reservationPayload(GuestApply $apply): array
    {
        $apply->loadMissing(['doorplate', 'guests']);
        $floor = $apply->doorplate?->floor;

        return [
            'reservation_id' => (string) $apply->reservation_id,
            'reservation_type' => (string) $apply->reservation_type,
            'visit_start_time' => $this->hhmm($apply->visit_start_time),
            'visit_end_time' => $this->hhmm($apply->visit_end_time),
            'host_company_name' => $apply->doorplate?->company_name,
            'host_floor' => $floor === null ? null : ((int) $floor < 0 ? 'B'.abs((int) $floor) : (string) (int) $floor),
            'host_name' => $apply->host_name,
            'visit_date' => $apply->visit_date?->toDateString(),
            'visit_reason' => $this->reasons->label((int) $apply->visit_reason),
            'guest_company_name' => $apply->guests->first()?->company_name,
            'visitor' => $apply->guests->map(fn ($g) => [
                'guest_job_title' => $g->title,
                'guest_name' => $g->name,
                'guest_phone' => $g->phone,
            ])->values()->all(),
            'id_card_slot_no' => $apply->id_card_slot_no,
            'guest_count' => (int) $apply->guest_count,
            'remark' => $apply->remark,
            'is_email_notify' => (bool) $apply->is_email_notify,
            'is_sms_notify' => (bool) $apply->is_sms_notify,
            'is_auto_remind' => (bool) $apply->is_auto_remind,
            'reservation_status' => (int) $apply->reservation_status,
            'fast_pass_code' => $apply->fast_pass_code,
            'access_card_no' => $apply->access_card_no,
            'created_at' => $apply->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $apply->updated_at?->format('Y-m-d H:i:s'),
            'check_in_at' => $apply->check_in_at?->format('Y-m-d H:i:s'),
            'check_out_at' => $apply->check_out_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 送出一則通知：沒啟用、沒勾選這個類型就直接略過（回 false）；否則交給佇列任務送出（回 true＝已排入佇列）。
     * 實際 POST 與重試（5 秒逾時、10 秒後重試、共 3 次失敗就丟棄）由 SendDashboardNotification 負責。
     * 絕不丟例外：排入佇列失敗只記 log，不能影響呼叫端的業務動作。
     */
    private function send(string $notifyType, array $body): bool
    {
        $setting = $this->settings();

        if (! $setting->shouldReport($notifyType)) {
            return false;
        }

        $message = $this->prepare($setting, $setting->url, $notifyType, $body);

        try {
            SendDashboardNotification::dispatch(
                $message['url'], $message['headers'], $message['payload'], $message['mask_keys'], config('database.connections.site_current'),
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('儀錶板上報排入佇列失敗', ['notify_type' => $notifyType, 'error' => $e->getMessage(), 'message_id' => $message['payload']['message_id']]);

            return false;
        }
    }

    /**
     * 連線測試：手動上報一次心跳（真的送出，內容與排程心跳相同）。
     * 同步送出、不重試，結果直接回給畫面；不看「是否啟用」與「上傳內容」，沒存檔前也能測。
     * $url 有帶就測該網址，沒帶用已儲存的網址。
     *
     * @return array{success: bool, status_code: ?int, duration_ms: int, tested_at: string, error: ?string, response_body: ?string}
     */
    public function testConnection(?string $url = null): array
    {
        $setting = $this->settings();
        $url = $url ?: $setting->url;

        if (! $url) {
            return ['success' => false, 'status_code' => null, 'duration_ms' => 0, 'tested_at' => now()->format('Y-m-d H:i:s'), 'error' => __('api.dashboard_report.url_missing'), 'response_body' => null];
        }

        $message = $this->prepare($setting, $url, 'heartbeat', [
            'nsst' => ['status' => 'online'],
            'kiosks' => $this->kiosks->snapshot(),
        ]);

        $result = DashboardClient::post($message['url'], $message['headers'], $message['payload']);
        DashboardReportLogger::record($result, $message['url'], $message['payload'], 1, true, $message['mask_keys']);

        return $result;
    }

    /**
     * 組信封（notify_type、message_id、sent_at）並加上自訂簽名（放標頭或 POST 內容）。
     * message_id 在這裡產生，之後任務重試都沿用同一個，對方可據此去重。
     *
     * @return array{url: string, headers: array<string, string>, payload: array, mask_keys: array<int, string>}
     */
    private function prepare(DashboardReportSetting $setting, string $url, string $notifyType, array $body): array
    {
        $payload = [
            'notify_type' => $notifyType,
            'message_id' => (string) Str::uuid(),
            'sent_at' => now()->format('Y-m-d H:i:s'),
        ] + $body;

        // 自訂簽名／固定資料：header 放標頭，body 放進 POST 內容最上層；不覆蓋通知本身的欄位
        $headers = [];
        $maskKeys = [];
        foreach ((array) $setting->auth_items as $item) {
            if (($item['location'] ?? 'header') === 'body') {
                if (! array_key_exists($item['name'], $payload)) {
                    $payload[$item['name']] = $item['value'];
                    $maskKeys[] = $item['name'];
                }
            } else {
                $headers[$item['name']] = (! empty($item['bearer']) ? 'Bearer ' : '').$item['value'];
            }
        }

        return ['url' => $url, 'headers' => $headers, 'payload' => $payload, 'mask_keys' => $maskKeys];
    }

    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
