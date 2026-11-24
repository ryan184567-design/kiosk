<?php

/* *****************************************
File: GuestDashboardReportController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 儀錶板上報設定控制器：讀取設定、儲存設定、連線測試（手動上報一次心跳）、查詢上報記錄。通知格式見 docs/DASHBOARD_REPORT.MD。
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\DashboardReportSetting;
use App\Api\Guest\Services\DashboardReportService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuestDashboardReportController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/guest/dashboard-settings
     * @api-tag GuestDashboardReport
     * @api-summary 取得儀錶板上報設定
     * @api-description
     * 每個子站一組設定；第一次讀取時系統會自動建立預設值（未啟用、上傳內容全選、沒有任何簽名）。
     * 自訂簽名／固定資料可設定多組（`auth_items`），值不回傳明文，只回傳遮罩後的值（只露出最後 4 碼）。
     * - 下拉／勾選項目的選項來源是資料字典，用 `GET /api/dictionaries/{字典代碼}/items` 取得（路徑可直接填字典代碼）：
     *   上傳方式 `dashboard_upload_method`、上傳格式 `dashboard_format`、上傳內容 `dashboard_content`、上傳排程 `dashboard_schedule`
     * @api-portal admin
     * @api-no-request-body
     * @api-dict dashboard_upload_method api=API 傳送
     * @api-dict dashboard_format json=JSON
     * @api-dict dashboard_content heartbeat=心跳及故障,approved=核准,modified=修改,cancelled=取消,checkin=報到,checkout=離場
     * @api-dict dashboard_schedule realtime=即時上傳
     * @api-dict dashboard_auth_location header=標頭 Header,body=POST 內容 Body
     * @api-response-field data.is_enabled bool 是否啟用上報
     * @api-response-field data.url string 儀錶板 API 網址，未設定為 null
     * @api-response-field data.upload_method dict:dashboard_upload_method 上傳方式，目前僅支援 api（API 傳送）
     * @api-response-field data.format dict:dashboard_format 上傳格式，目前僅支援 json
     * @api-response-field data.contents array 上傳內容，勾選的項目；可選值與意義（字典 dashboard_content）：heartbeat=心跳及訪客機故障、approved=核准、modified=修改、cancelled=取消、checkin=報到、checkout=離場
     * @api-response-field data.schedule dict:dashboard_schedule 上傳排程，目前僅支援 realtime
     * @api-response-field data.auth_items array 自訂簽名／固定資料，可多組，沒設定為空陣列；每次上報都會帶上全部
     * @api-response-field data.auth_items[].name string 欄位名稱，例如 X-API-Key
     * @api-response-field data.auth_items[].location dict:dashboard_auth_location 放的位置：header=標頭、body=POST 內容最上層
     * @api-response-field data.auth_items[].bearer bool 值前是否加 "Bearer "（僅 header 有效）
     * @api-response-field data.auth_items[].value_masked string 遮罩後的值（只露出最後 4 碼），明文不回傳
     * @api-response-field data.updated_at string 最後更新時間
     */
    public function dashboardSettingShow(DashboardReportService $service)
    {
        return $this->success($this->present($service->settings()));
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/dashboard-settings
     * @api-tag GuestDashboardReport
     * @api-summary 儲存儀錶板上報設定
     * @api-description
     * 整份設定一次儲存。
     * - 啟用（`is_enabled` 為 true）時 `url` 必填
     * - `upload_method`、`format`、`schedule` 目前各只支援一個值（api、json、realtime），可省略
     * - `contents` 決定哪些事件要上報，可選值（字典 `dashboard_content`）：
     *   `heartbeat`=心跳及訪客機故障（nsst 每分鐘上報一次心跳）、`approved`=核准、`modified`=修改、`cancelled`=取消、`checkin`=報到、`checkout`=離場；
     *   核准、修改、取消、報到、離場為預約事件，即時上報。傳空陣列代表都不上報
     * - 下拉／勾選項目的選項來源是資料字典，用 `GET /api/dictionaries/{字典代碼}/items` 取得（路徑可直接填字典代碼）：
     *   上傳方式 `dashboard_upload_method`、上傳格式 `dashboard_format`、上傳內容 `dashboard_content`、上傳排程 `dashboard_schedule`
     * - 自訂簽名／固定資料（`auth_items`）可設定多組，每組一個欄位，每次上報都會帶上全部：
     *   `location=header` 放進 HTTP 標頭（`bearer` 為 true 時值前加 `Bearer `）；`location=body` 放進 POST 內容最上層，當固定資料值。
     *   例：標頭 `X-API-Key`（簽名）＋ POST 內容 `site_code`（固定值）
     * - `auth_items` 省略代表整組沿用；有帶就是整份取代（要全部清除傳空陣列）。某一組的 `value` 省略或 null，沿用原本「同名稱＋同位置」那組的值
     *   （因為介面不回傳明文，改別的欄位時不必重送值）；新增的組一定要有值。同位置的名稱不可重複，body 的名稱不可用 notify_type、message_id、sent_at
     * @api-portal admin
     * @api-dict dashboard_upload_method api=API 傳送
     * @api-dict dashboard_format json=JSON
     * @api-dict dashboard_content heartbeat=心跳及故障,approved=核准,modified=修改,cancelled=取消,checkin=報到,checkout=離場
     * @api-dict dashboard_schedule realtime=即時上傳
     * @api-dict dashboard_auth_location header=標頭 Header,body=POST 內容 Body
     * @api-request-field is_enabled bool 是否啟用上報
     * @api-request-field url string 儀錶板 API 網址（http 或 https），啟用時必填，最長 500 字
     * @api-request-field upload_method dict:dashboard_upload_method 上傳方式，選填，目前僅支援 api（API 傳送）；選項來源字典 dashboard_upload_method
     * @api-request-field format dict:dashboard_format 上傳格式，選填，目前僅支援 json（JSON）；選項來源字典 dashboard_format
     * @api-request-field contents array 上傳內容，勾選的項目，必填，傳空陣列代表都不上報；可選值與意義（字典 dashboard_content）：heartbeat=心跳及訪客機故障、approved=核准、modified=修改、cancelled=取消、checkin=報到、checkout=離場
     * @api-request-field schedule dict:dashboard_schedule 上傳排程，選填，目前僅支援 realtime（即時上傳）；選項來源字典 dashboard_schedule
     * @api-request-field auth_items array 自訂簽名／固定資料，選填，可多組；省略沿用原設定，傳空陣列清除全部
     * @api-request-field auth_items[].name string 欄位名稱，必填，只能英數、底線、連字號，最長 100 字，例如 X-API-Key
     * @api-request-field auth_items[].location dict:dashboard_auth_location 放的位置，必填：header=標頭、body=POST 內容最上層
     * @api-request-field auth_items[].bearer bool 值前是否加 "Bearer "，選填，僅 header 有效
     * @api-request-field auth_items[].value string 值，最長 500 字；既有的組可省略（沿用原值），新增的組必填
     * @api-request-example {"is_enabled":true,"url":"https://dashboard.example.com/api/notify","contents":["heartbeat","approved","modified","cancelled","checkin","checkout"],"auth_items":[{"name":"X-API-Key","location":"header","bearer":false,"value":"j2KrhxfhXSfHQ14huKDsBEvxxOfz2iNWIPfxo4AKosw"},{"name":"site_code","location":"body","value":"NSST-01"}]}
     * @api-response-field data.is_enabled bool 是否啟用上報
     * @api-response-field data.url string 儀錶板 API 網址，未設定為 null
     * @api-response-field data.upload_method dict:dashboard_upload_method 上傳方式
     * @api-response-field data.format dict:dashboard_format 上傳格式
     * @api-response-field data.contents array 上傳內容
     * @api-response-field data.schedule dict:dashboard_schedule 上傳排程
     * @api-response-field data.auth_items array 自訂簽名／固定資料，可多組
     * @api-response-field data.auth_items[].name string 欄位名稱，例如 X-API-Key
     * @api-response-field data.auth_items[].location dict:dashboard_auth_location 放的位置：header=標頭、body=POST 內容最上層
     * @api-response-field data.auth_items[].bearer bool 值前是否加 "Bearer "（僅 header 有效）
     * @api-response-field data.auth_items[].value_masked string 遮罩後的值（只露出最後 4 碼），明文不回傳
     * @api-response-field data.updated_at string 最後更新時間
     */
    public function dashboardSettingUpdate(Request $request, DashboardReportService $service)
    {
        $data = $this->validateInput($request, [
            'is_enabled' => 'required|boolean',
            'url' => ['nullable', 'url:http,https', 'max:500', Rule::requiredIf(fn () => $request->boolean('is_enabled'))],
            'upload_method' => ['nullable', Rule::in(['api'])],
            'format' => ['nullable', Rule::in(['json'])],
            'contents' => 'required|array',
            'contents.*' => ['required', 'string', Rule::in(array_keys(DashboardReportSetting::CONTENT_NOTIFY_TYPES))],
            'schedule' => ['nullable', Rule::in(['realtime'])],
            'auth_items' => 'nullable|array|max:20',
            'auth_items.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'auth_items.*.location' => ['required', Rule::in(['header', 'body'])],
            'auth_items.*.bearer' => 'nullable|boolean',
            'auth_items.*.value' => 'nullable|string|max:500',
        ]);

        $data['contents'] = array_values(array_unique($data['contents']));

        // 沒帶的選填欄位（null）一律沿用原值；只有 url 允許存成 null（清空網址）
        $data = array_filter($data, fn ($v, $k) => $v !== null || $k === 'url', ARRAY_FILTER_USE_BOTH);

        return $this->success($this->present($service->updateSettings($data)));
    }

    /**
     * @api-method POST
     * @api-path /api/guest/dashboard-settings/test
     * @api-tag GuestDashboardReport
     * @api-summary 連線測試（手動上報一次心跳）
     * @api-description
     * 立刻手動上報一次心跳到儀錶板，內容與排程心跳完全相同，用來確認網址與簽名是否正確。
     * - 不看「是否啟用」與「上傳內容」，還沒儲存設定也能測
     * - `url` 選填：有帶就測這個網址（可以測畫面上尚未儲存的網址），沒帶用已儲存的網址；簽名使用已儲存的設定
     * - 不論成功或失敗，這支接口本身都回 200，結果看 `success`；儀錶板回非 2xx 或連不上時 `success` 為 false，`error` 說明原因
     * - 儀錶板最多等 5 秒
     * - 儀錶板的回應內容原文放在 `response_body`；這次測試也會寫進上報記錄（`GET /api/guest/dashboard-logs`，`is_test` 為 true）
     * @api-portal admin
     * @api-request-field url string 要測試的網址，選填，沒帶用已儲存的網址
     * @api-request-example {"url":"https://dashboard.example.com/api/notify"}
     * @api-response-field data.success bool 儀錶板是否回了 2xx
     * @api-response-field data.status_code int 儀錶板回應的 HTTP 狀態碼，連不上或逾時為 null
     * @api-response-field data.duration_ms int 回應時間（毫秒）
     * @api-response-field data.tested_at string 測試時間
     * @api-response-field data.error string 失敗原因，成功為 null
     * @api-response-field data.response_body string 儀錶板回應的內容原文（最多 5000 字），連不上或逾時為 null；可用來確認對方實際回了什麼
     */
    public function dashboardSettingTest(Request $request, DashboardReportService $service)
    {
        $data = $this->validateInput($request, [
            'url' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        return $this->success($service->testConnection($data['url'] ?? null));
    }

    /**
     * @api-method GET
     * @api-path /api/guest/dashboard-logs
     * @api-tag GuestDashboardReport
     * @api-summary 儀錶板上報記錄列表
     * @api-description
     * 每一次實際送出到儀錶板的結果都會留一筆記錄（含重試的每一次、連線測試），記下送出的內容與儀錶板的回應，新的在前。
     * - 同一則通知的重試共用同一個 `message_id`，`attempt` 為第幾次嘗試（最多 3）
     * - 標頭不記錄；放在 POST 內容的簽名／固定資料值已遮罩（只露出最後 4 碼）
     * - `per_page` 傳 -1 代表不分頁，省略時預設 10
     * @api-portal admin
     * @api-dict dashboard_notify_type heartbeat=心跳,device_fault=訪客機故障,reservation_approved=核准,reservation_modified=修改,reservation_cancelled=取消,reservation_checkin=報到,reservation_checkout=離場
     * @api-query-field notify_type string optional 通知類型：heartbeat=心跳、device_fault=訪客機故障、reservation_approved=核准、reservation_modified=修改、reservation_cancelled=取消、reservation_checkin=報到、reservation_checkout=離場
     * @api-query-field success int optional 是否成功，1=成功（對方回 2xx）、0=失敗，未傳則全部
     * @api-query-field is_test int optional 是否連線測試，1=只看連線測試、0=不含連線測試，未傳則全部
     * @api-query-field date_from string optional 起始日期 YYYY-MM-DD
     * @api-query-field date_to string optional 結束日期 YYYY-MM-DD，不可早於 date_from
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部，未傳則預設 10
     * @api-response-field data.items[].id int 記錄ID
     * @api-response-field data.items[].notify_type dict:dashboard_notify_type 通知類型
     * @api-response-field data.items[].message_id string 通知識別碼，同一則通知的重試相同
     * @api-response-field data.items[].attempt int 第幾次嘗試，連線測試固定 1
     * @api-response-field data.items[].is_test bool 是否為連線測試
     * @api-response-field data.items[].url string 送出的網址
     * @api-response-field data.items[].success bool 儀錶板是否回了 2xx
     * @api-response-field data.items[].status_code int 儀錶板回應的 HTTP 狀態碼，連不上或逾時為 null
     * @api-response-field data.items[].duration_ms int 花費時間（毫秒）
     * @api-response-field data.items[].request_body object 送出的 POST 內容（簽名值已遮罩）
     * @api-response-field data.items[].response_body string 儀錶板回應的內容原文（最多 5000 字），沒有回應為 null
     * @api-response-field data.items[].error string 失敗原因，成功為 null
     * @api-response-field data.items[].created_at string 送出時間
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function dashboardLogs(Request $request, DashboardReportService $service)
    {
        $data = $this->validateInput($request, [
            'notify_type' => ['nullable', 'string', Rule::in(collect(DashboardReportSetting::CONTENT_NOTIFY_TYPES)->flatten()->merge(array_values(DashboardReportService::RESERVATION_NOTIFY_TYPES))->unique()->all())],
            'success' => 'nullable|boolean',
            'is_test' => 'nullable|boolean',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->logs(
                $data['notify_type'] ?? null,
                isset($data['success']) ? (bool) $data['success'] : null,
                isset($data['is_test']) ? (bool) $data['is_test'] : null,
                $data['date_from'] ?? null,
                $data['date_to'] ?? null,
            ),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn ($log) => [
            'id' => $log->id,
            'notify_type' => $log->notify_type,
            'message_id' => $log->message_id,
            'attempt' => $log->attempt,
            'is_test' => $log->is_test,
            'url' => $log->url,
            'success' => $log->success,
            'status_code' => $log->status_code,
            'duration_ms' => $log->duration_ms,
            'request_body' => $log->request_body,
            'response_body' => $log->response_body,
            'error' => $log->error,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ])->all();

        return $this->success($result);
    }

    private function present(DashboardReportSetting $setting): array
    {
        return [
            'is_enabled' => $setting->is_enabled,
            'url' => $setting->url,
            'upload_method' => $setting->upload_method,
            'format' => $setting->format,
            'contents' => $setting->contents ?? [],
            'schedule' => $setting->schedule,
            'auth_items' => collect($setting->auth_items ?? [])->map(fn ($item) => [
                'name' => $item['name'],
                'location' => $item['location'],
                'bearer' => (bool) ($item['bearer'] ?? false),
                'value_masked' => DashboardReportService::maskSecret($item['value'] ?? null),
            ])->values()->all(),
            'updated_at' => $setting->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
