<?php

/* *****************************************
File: AppLogController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 後台查看 App 簽發與調用記錄
***************************************** */

namespace App\Api\Factory\Controllers;

use App\Api\Factory\Models\FactoryLog;
use App\Api\Factory\Services\FactoryAppService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

class AppLogController extends BaseApiController
{
    use MapsServiceExceptions, PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/system/factory/logs
     * @api-tag FactoryLog
     * @api-summary 廠商 App 記錄搜尋
     * @api-description
     * 查詢 token 簽發、scope 被拒、API 呼叫（失敗，或該 App 開了除錯模式時的每次呼叫）、後台對 App 的操作記錄，由新到舊。
     * 可依 **App ID、業務模塊、具體方法、結果、時間** 任意組合搜尋，條件之間為「且」。
     * - `module`：被呼叫的業務模塊代碼，例如 `guest`、`factory`
     * - `action`：具體方法，`Controller@method`，模糊比對，例如 `FactoryGuestApplyController@index` 或只填 `index`
     * - `success`：結果，1=成功（2xx） 0=失敗；`status_code` 可進一步指定 HTTP 狀態碼
     * - `date_from`／`date_to`：只給日期（YYYY-MM-DD）涵蓋整天，也可給到秒（YYYY-MM-DD HH:mm:ss）
     * 列表不含傳入參數與完整回應（可能很大），要看請呼叫記錄詳情 `GET /api/system/factory/logs/{id}`。
     * `reason` 為失敗原因或操作說明，**只在後台可見**，不會回給呼叫方。
     * @api-portal admin
     * @api-dict factory_log_type token=簽發 token,scope_denied=權限被拒,call=API 呼叫,admin=後台操作
     * @api-request-field app_id string 對外 app_id，選填
     * @api-request-field module string 業務模塊代碼，選填
     * @api-request-field action string 具體方法（Controller@method，模糊比對），選填
     * @api-request-field method string HTTP 方法 GET／POST／PUT／PATCH／DELETE，選填
     * @api-request-field type dict:factory_log_type 記錄類型，選填
     * @api-request-field success int 結果 1=成功 0=失敗，選填
     * @api-request-field status_code int HTTP 狀態碼，選填
     * @api-request-field date_from string 起始時間，YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss，選填
     * @api-request-field date_to string 結束時間，格式同上，選填
     * @api-request-field page int 頁碼，預設 1
     * @api-request-field per_page int 每頁筆數，預設 10，最大 200；-1 代表不分頁
     * @api-request-example {"app_id":"app_k3x9m2p0q7w1z5ab","module":"guest","action":"FactoryGuestApplyController@index","success":0,"date_from":"2026-11-01","date_to":"2026-11-30"}
     * @api-response-field data.items[].id int 記錄ID
     * @api-response-field data.items[].app_id string 對外 app_id
     * @api-response-field data.items[].type dict:factory_log_type 記錄類型
     * @api-response-field data.items[].success bool 是否成功
     * @api-response-field data.items[].module string 被呼叫的業務模塊代碼，無法判斷時為 null
     * @api-response-field data.items[].action string 具體方法 Controller@method，無法判斷時為 null
     * @api-response-field data.items[].method string HTTP 方法
     * @api-response-field data.items[].path string 請求路徑
     * @api-response-field data.items[].route string 路由樣式，如 /api/factory/guest/applies/{id}
     * @api-response-field data.items[].status_code int HTTP 狀態碼，非 API 呼叫類記錄為 null
     * @api-response-field data.items[].ip string 來源 IP
     * @api-response-field data.items[].scope string 相關 scope
     * @api-response-field data.items[].reason string 失敗原因或操作說明（API 呼叫為 debug／non_2xx，表示為何被記錄）
     * @api-response-field data.items[].duration_ms int 處理耗時（毫秒），非 API 呼叫類記錄為 null
     * @api-response-field data.items[].started_at datetime 請求起始時間（毫秒精度），非 API 呼叫類記錄為 null
     * @api-response-field data.items[].created_at datetime 寫入記錄的時間
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function logIndex(Request $request, FactoryAppService $service)
    {
        $data = $this->validateInput($request, [
            'app_id' => 'nullable|string|max:40',
            'module' => 'nullable|string|max:50',
            'action' => 'nullable|string|max:150',
            'method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,get,post,put,patch,delete',
            'type' => 'nullable|string|in:token,scope_denied,admin,call',
            'success' => 'nullable|integer|in:0,1',
            'status_code' => 'nullable|integer|min:100|max:599',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        $data['success'] = isset($data['success']) ? (bool) $data['success'] : null;
        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->logs($data),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (FactoryLog $l) => $this->presentList($l))->all();

        return $this->success($result);
    }

    /**
     * @api-method GET
     * @api-path /api/system/factory/logs/{id}
     * @api-tag FactoryLog
     * @api-summary 廠商 App 記錄詳情
     * @api-description
     * 取得單筆記錄的完整內容。API 呼叫記錄包含：
     * - `request_data`（JSON 字串）：`client`（IP、IP 鏈、User-Agent、Referer、協定、主機…）、完整 `url`、`route_params`、
     *   `query`（GET）、`form`（POST 表單）、`json`、`files`（只記檔名／大小／類型）、`raw_body`、所有 `headers`
     * - `response_headers`（JSON 字串）、`response_data`（完整回應內容）、`status_code`、`response_size`
     * - 時間資訊：`started_at`（請求起始，毫秒）、`duration_ms`（耗時）、`created_at`（寫入記錄的時間）
     * 敏感值（password、secret、token、authorization、cookie 等）在寫入時已遮罩為 `***`；
     * 每個區塊（headers、query、json…）各自超過上限（`FACTORY_LOG_MAX_BYTES`，預設 64KB）會被截斷並標註。
     * 記錄保留 `FACTORY_LOG_RETENTION_DAYS` 天（預設 30），超過自動移除。
     * @api-portal admin
     * @api-dict factory_log_type token=簽發 token,scope_denied=權限被拒,call=API 呼叫,admin=後台操作
     * @api-no-request-body
     * @api-response-field data.id int 記錄ID
     * @api-response-field data.app_id string 對外 app_id
     * @api-response-field data.type dict:factory_log_type 記錄類型
     * @api-response-field data.success bool 是否成功
     * @api-response-field data.module string 被呼叫的業務模塊代碼
     * @api-response-field data.action string 具體方法 Controller@method
     * @api-response-field data.method string HTTP 方法
     * @api-response-field data.path string 請求路徑
     * @api-response-field data.status_code int HTTP 狀態碼
     * @api-response-field data.ip string 來源 IP
     * @api-response-field data.scope string 相關 scope
     * @api-response-field data.reason string 失敗原因或操作說明
     * @api-response-field data.route string 路由樣式，如 /api/factory/guest/applies/{id}
     * @api-response-field data.started_at datetime 請求起始時間（毫秒精度）
     * @api-response-field data.duration_ms int 處理耗時（毫秒）
     * @api-response-field data.request_data string 請求完整資訊（JSON 字串：client、url、route_params、query、form、json、files、raw_body、headers），非 API 呼叫類記錄為 null
     * @api-response-field data.response_headers string 回應 header（JSON 字串），非 API 呼叫類記錄為 null
     * @api-response-field data.response_data string 完整回應內容，非 API 呼叫類記錄為 null
     * @api-response-field data.response_size int 回應內容大小（bytes，截斷前）
     * @api-response-field data.created_at datetime 寫入記錄的時間
     */
    public function logShow(int $id, FactoryAppService $service)
    {
        try {
            $log = $service->logDetail($id);
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success(array_merge($this->presentList($log), [
            'request_data' => $log->request_data,
            'response_headers' => $log->response_headers,
            'response_data' => $log->response_data,
            'response_size' => $log->response_size,
        ]));
    }

    private function presentList(FactoryLog $l): array
    {
        return [
            'id' => $l->id,
            'app_id' => $l->app_id,
            'type' => $l->type,
            'success' => $l->success,
            'module' => $l->module,
            'action' => $l->action,
            'method' => $l->method,
            'path' => $l->path,
            'route' => $l->route,
            'status_code' => $l->status_code,
            'ip' => $l->ip,
            'scope' => $l->scope,
            'reason' => $l->reason,
            'duration_ms' => $l->duration_ms,
            'started_at' => $l->started_at?->format('Y-m-d H:i:s.v'),
            'created_at' => $l->created_at?->toDateTimeString(),
        ];
    }
}
