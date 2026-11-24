<?php

/* *****************************************
File: FactoryGuestKioskController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客機狀態回報接口（App 認證 + scope 授權）：定時心跳、硬體故障回報
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\GuestKioskStatus;
use App\Api\Guest\Services\GuestKioskService;
use App\Support\Dashboard\DashboardReporter;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。訪客機身份（kiosk_id）一律取 token 對應的 App，不由訪客機自己傳。
class FactoryGuestKioskController extends BaseApiController
{
    /**
     * @api-method POST
     * @api-path /api/factory/guest/kiosk/heartbeat
     * @api-tag Factory
     * @api-summary 訪客機定時回報心跳
     * @api-portal *
     * @api-auth app
     * @api-scope guest.kiosk.heartbeat
     * @api-description
     * 訪客機每 30 秒呼叫一次，證明自己還活著；系統記錄「最後回報時間」，nsst 每分鐘給儀錶板上報一次心跳時，
     * 最後回報時間超過 1 分鐘的訪客機會被標為離線（故障）。
     * - `faults` 選填：訪客機目前**仍未恢復**的故障清單（沒有故障傳空陣列 `[]`）。有帶時以它為準整批覆蓋，
     *   漏收的故障或恢復回報下次心跳就能補正；沒帶則不動目前記錄的故障。
     * - 故障代碼 `fault_code` 見 `POST /api/factory/guest/kiosk/fault`
     * @api-dict guest_kiosk_fault_code PRINTER_PAPER_OUT=熱敏出單機缺紙,PRINTER_DISCONNECTED=熱敏出單機連接故障,ID_SCANNER_DISCONNECTED=身份證掃描器連接故障
     * @api-request-field faults array 目前仍未恢復的故障清單，選填；無故障傳 []
     * @api-request-field faults[].fault_code dict:guest_kiosk_fault_code 故障代碼
     * @api-request-field faults[].occurred_at string 故障發生時間 YYYY-MM-DD HH:mm:ss，選填，省略為現在
     * @api-request-example {"faults":[{"fault_code":"PRINTER_PAPER_OUT","occurred_at":"2026-11-23 15:20:11"}]}
     * @api-response-field data.server_time string 伺服器時間 YYYY-MM-DD HH:mm:ss，訪客機可用來校時
     */
    public function heartbeat(Request $request, GuestKioskService $service)
    {
        $data = $this->validateInput($request, [
            'faults' => 'nullable|array|max:10',
            'faults.*.fault_code' => ['required', 'string', Rule::in(GuestKioskStatus::FAULT_CODES)],
            'faults.*.occurred_at' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        $service->heartbeat(
            $request->attributes->get('factory_app')->app_id,
            $data['faults'] ?? null,
            $request->ip(),
            $data,
        );

        return $this->success(['server_time' => now()->format('Y-m-d H:i:s')]);
    }

    /**
     * @api-method POST
     * @api-path /api/factory/guest/kiosk/fault
     * @api-tag Factory
     * @api-summary 訪客機回報硬體故障、恢復或系統正常
     * @api-portal *
     * @api-auth app
     * @api-scope guest.kiosk.fault
     * @api-description
     * 訪客機偵測到硬體異常時**立即**呼叫；故障排除時也要呼叫一次告知已恢復。
     * - `fault_code` 為 `SYSTEM_OK`（系統正常）時，表示這台訪客機所有故障都已排除，會清除目前記錄的全部故障，`fault_status` 可省略。
     * - 其他故障代碼：`fault_status` 為 `occurred`（發生，省略時的預設值）或 `resolved`（已恢復）。
     * - 同一種故障重複回報 `occurred`、或沒有故障時回報 `SYSTEM_OK`，不會再通知儀錶板，也不算錯誤。
     * - 這支接口同樣會更新「最後回報時間」，所以回報故障時不必另外呼叫心跳。
     * - 狀態有變動時，系統會依「儀錶板上報設定」即時上報儀錶板（`notify_type = device_fault`，算在上傳內容「心跳」底下）。
     * @api-dict guest_kiosk_fault_code PRINTER_PAPER_OUT=熱敏出單機缺紙,PRINTER_DISCONNECTED=熱敏出單機連接故障,ID_SCANNER_DISCONNECTED=身份證掃描器連接故障,SYSTEM_OK=系統正常
     * @api-dict guest_kiosk_fault_status occurred=發生,resolved=已恢復
     * @api-request-field fault_code dict:guest_kiosk_fault_code 故障代碼
     * @api-request-field fault_status dict:guest_kiosk_fault_status 故障狀態，選填，預設 occurred；fault_code 為 SYSTEM_OK 時忽略
     * @api-request-field occurred_at string 事件發生時間 YYYY-MM-DD HH:mm:ss，選填，省略為現在
     * @api-request-example {"fault_code":"PRINTER_PAPER_OUT","fault_status":"occurred","occurred_at":"2026-11-23 15:20:11"}
     * @api-response-field data.changed bool 這次回報是否讓故障狀態有變動（false＝重複回報，未再通知儀錶板）
     * @api-response-field data.faults array 回報後這台訪客機目前仍未恢復的故障
     * @api-response-field data.faults[].fault_code dict:guest_kiosk_fault_code 故障代碼
     * @api-response-field data.faults[].occurred_at string 故障發生時間
     */
    public function fault(Request $request, GuestKioskService $service)
    {
        $data = $this->validateInput($request, [
            'fault_code' => ['required', 'string', Rule::in([...GuestKioskStatus::FAULT_CODES, GuestKioskStatus::SYSTEM_OK])],
            'fault_status' => ['nullable', 'string', Rule::in(['occurred', 'resolved'])],
            'occurred_at' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        $appId = $request->attributes->get('factory_app')->app_id;
        $faultCode = $data['fault_code'];
        $isOk = $faultCode === GuestKioskStatus::SYSTEM_OK;
        $faultStatus = $isOk ? 'resolved' : ($data['fault_status'] ?? 'occurred');
        $occurredAt = $data['occurred_at'] ?? now()->format('Y-m-d H:i:s');

        $result = $service->fault($appId, $faultCode, $faultStatus, $occurredAt, $request->ip(), $data);

        if ($result['changed']) {
            DashboardReporter::deviceFault($appId, $faultCode, $faultStatus, __('api.guest_kiosk.fault_messages.'.$faultCode), $occurredAt);
        }

        return $this->success([
            'changed' => $result['changed'],
            'faults' => $result['status']->faults ?? [],
        ]);
    }
}
