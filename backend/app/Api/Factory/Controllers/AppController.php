<?php

/* *****************************************
File: AppController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 後台「App 管理」：App 的建立、編輯、重置 app_secret、scope 授權、停用、刪除。
             這是「後台用戶」呼叫的接口（jwt.auth + permission.api），與訪客機呼叫的
             FactoryGuestApplyController 等機器接口（factory.auth + factory.scope）是兩套互不相干的認證。
***************************************** */

namespace App\Api\Factory\Controllers;

use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Services\FactoryAppService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

class AppController extends BaseApiController
{
    use MapsServiceExceptions, PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/system/factory/apps
     * @api-tag FactoryApp
     * @api-summary 廠商 App 列表
     * @api-description
     * 列出所有App 及其已授權的 scope 清單。**不會**回傳 app_secret。
     * `per_page` 傳 -1 代表不分頁，省略時預設 10。
     * @api-portal admin
     * @api-request-field keyword string 關鍵字（名稱／app_id），選填
     * @api-request-field status int 狀態 1=啟用 0=停用，選填
     * @api-request-field page int 頁碼，預設 1
     * @api-request-field per_page int 每頁筆數，預設 10，最大 200；-1 代表不分頁
     * @api-request-example {"keyword":"1 號","status":1,"page":1,"per_page":10}
     * @api-response-field data.items[].id int App 主鍵
     * @api-response-field data.items[].app_id string 對外 app_id
     * @api-response-field data.items[].name string 名稱
     * @api-response-field data.items[].status bool 是否啟用
     * @api-response-field data.items[].is_debug bool 是否為除錯模式（true=每次呼叫都記錄，false=只記錄非 2xx）
     * @api-response-field data.items[].allowed_ips array IP/CIDR 白名單，空代表不限制
     * @api-response-field data.items[].expired_at datetime 過期時間，未設為 null
     * @api-response-field data.items[].last_used_at datetime 最近簽發 token 時間
     * @api-response-field data.items[].scopes array 已授權 scope（可含 `模塊.*`、`*`）
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function appIndex(Request $request, FactoryAppService $service)
    {
        $data = $this->validateInput($request, [
            'keyword' => 'nullable|string|max:100',
            'status' => 'nullable|integer|in:0,1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->apps($data['keyword'] ?? null, isset($data['status']) ? (bool) $data['status'] : null),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (FactoryApp $a) => $this->present($a))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/system/factory/apps
     * @api-tag FactoryApp
     * @api-summary 新增廠商 App
     * @api-description
     * 建立 App 並可同時授權 scope。回應的 `app_secret` **只會在此刻出現一次**，
     * 之後無法再查詢，遺失只能「重置 app_secret」。授權 `*`（全部權限）請謹慎。
     * @api-portal admin
     * @api-request-field name string 名稱，必填，最長 100 字，例如「自助訪客機 1 號」
     * @api-request-field allowed_ips array IP/CIDR 白名單，選填，空代表不限制
     * @api-request-field expired_at string 過期時間 YYYY-MM-DD HH:mm:ss，選填
     * @api-request-field remark string 備註，選填，最長 255 字
     * @api-request-field is_debug bool 除錯模式，選填，預設 false。開啟後這個 App 的每次呼叫（成功失敗都）記錄傳入參數與完整回應；關閉時只記錄非 2xx 的呼叫。資料量大，除錯完請關閉
     * @api-request-field scopes array 授權 scope 清單，選填，例如 ["guest.apply.read"] 或 ["guest.*"]
     * @api-request-example {"name":"自助訪客機 1 號","allowed_ips":["192.168.1.0/24"],"expired_at":null,"remark":"一樓大廳","is_debug":false,"scopes":["guest.apply.read"]}
     * @api-response-field data.id int App 主鍵
     * @api-response-field data.app_id string 對外 app_id
     * @api-response-field data.app_secret string app_secret 明文，僅此一次
     */
    public function appStore(Request $request, FactoryAppService $service)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'string|max:64',
            'expired_at' => 'nullable|date_format:Y-m-d H:i:s',
            'remark' => 'nullable|string|max:255',
            'is_debug' => 'nullable|boolean',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string|max:100',
        ]);

        try {
            [$app, $appKey] = $service->create(
                $data['name'], $data['allowed_ips'] ?? null, $data['expired_at'] ?? null,
                $data['remark'] ?? null, $data['scopes'] ?? [], $this->operator($request),
                (bool) ($data['is_debug'] ?? false)
            );
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success(['id' => $app->id, 'app_id' => $app->app_id, 'app_secret' => $appKey], 201);
    }

    /**
     * @api-method GET
     * @api-path /api/system/factory/apps/{id}
     * @api-tag FactoryApp
     * @api-summary 廠商 App 詳情
     * @api-description 取得單一 App 與其授權，**不會**回傳 app_secret。
     * @api-portal admin
     * @api-no-request-body
     * @api-response-field data.id int App 主鍵
     * @api-response-field data.app_id string 對外 app_id
     * @api-response-field data.name string 名稱
     * @api-response-field data.status bool 是否啟用
     * @api-response-field data.is_debug bool 是否為除錯模式（true=每次呼叫都記錄，false=只記錄非 2xx）
     * @api-response-field data.allowed_ips array IP/CIDR 白名單
     * @api-response-field data.expired_at datetime 過期時間
     * @api-response-field data.remark string 備註
     * @api-response-field data.last_used_at datetime 最近簽發 token 時間
     * @api-response-field data.scopes array 已授權 scope
     */
    public function appShow(int $id, FactoryAppService $service)
    {
        try {
            return $this->success($this->present($service->find($id)));
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }
    }

    /**
     * @api-method PUT
     * @api-path /api/system/factory/apps/{id}
     * @api-tag FactoryApp
     * @api-summary 修改廠商 App
     * @api-description
     * 修改名稱、白名單、過期時間、備註與啟用狀態（停用即立即生效）。不處理 app_secret 與授權，
     * 那兩項有各自的接口。
     * @api-portal admin
     * @api-request-field name string 名稱，必填
     * @api-request-field status bool 是否啟用，必填
     * @api-request-field allowed_ips array IP/CIDR 白名單，選填
     * @api-request-field expired_at string 過期時間 YYYY-MM-DD HH:mm:ss，選填，傳 null 代表不過期
     * @api-request-field remark string 備註，選填
     * @api-request-field is_debug bool 除錯模式，選填；不傳代表不改。true=每次呼叫都記錄，false=只記錄非 2xx
     * @api-request-example {"name":"自助訪客機 1 號","status":true,"allowed_ips":[],"expired_at":null,"remark":"一樓大廳","is_debug":true}
     * @api-response-empty
     */
    public function appUpdate(int $id, Request $request, FactoryAppService $service)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'status' => 'required|boolean',
            'is_debug' => 'sometimes|boolean',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'string|max:64',
            'expired_at' => 'nullable|date_format:Y-m-d H:i:s',
            'remark' => 'nullable|string|max:255',
        ]);

        $data['allowed_ips'] = ($data['allowed_ips'] ?? []) ?: null;

        try {
            $service->update($id, $data, $this->operator($request));
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/system/factory/apps/{id}
     * @api-tag FactoryApp
     * @api-summary 刪除廠商 App
     * @api-description 軟刪除，該 App 的 token 立即失效；歷史日誌以 app_id 保留。
     * @api-portal admin
     * @api-no-request-body
     * @api-response-empty
     */
    public function appDestroy(int $id, Request $request, FactoryAppService $service)
    {
        try {
            $service->delete($id, $this->operator($request));
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method POST
     * @api-path /api/system/factory/apps/{id}/reset-secret
     * @api-tag FactoryApp
     * @api-summary 重置 app_secret
     * @api-description
     * 產生新的 app_secret，**舊 key 立即失效**。新 key 只在這次回應出現一次。
     * 已簽發的 access token 在到期前仍有效；若需要立即中斷請同時停用 App。
     * @api-portal admin
     * @api-no-request-body
     * @api-response-field data.app_id string 對外 app_id
     * @api-response-field data.app_secret string 新的 app_secret 明文，僅此一次
     */
    public function appResetKey(int $id, Request $request, FactoryAppService $service)
    {
        try {
            [$app, $appKey] = $service->resetKey($id, $this->operator($request));
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success(['app_id' => $app->app_id, 'app_secret' => $appKey]);
    }

    /**
     * @api-method PUT
     * @api-path /api/system/factory/apps/{id}/scopes
     * @api-tag FactoryApp
     * @api-summary 設定 App 授權
     * @api-description
     * 以傳入的清單**覆蓋**該 App 的全部授權。支援單一 scope（`guest.apply.read`）、
     * 整個模塊（`guest.*`）與全部（`*`）。快取最多 60 秒內生效。
     * @api-portal admin
     * @api-request-field scopes array 完整授權清單，可為空陣列（代表全部取消）
     * @api-request-example {"scopes":["guest.apply.read"]}
     * @api-response-empty
     */
    public function appSetScopes(int $id, Request $request, FactoryAppService $service)
    {
        $data = $this->validateInput($request, [
            'scopes' => 'present|array',
            'scopes.*' => 'string|max:100',
        ]);

        try {
            $service->setScopes($id, $data['scopes'], $this->operator($request));
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/system/factory/scopes
     * @api-tag FactoryApp
     * @api-summary 可授權的 scope 清單
     * @api-description
     * 列出已登記的 scope（依模塊分組），供授權界面勾選。新接口掛上 `factory.scope:xxx` 後，
     * 執行 `php artisan factory:sync-scopes` 即會出現在這裡。
     * @api-portal admin
     * @api-response-field data.items[].module string 模塊代碼
     * @api-response-field data.items[].scopes array 該模塊下的 scope
     * @api-response-field data.items[].scopes[].code string scope 代碼
     * @api-response-field data.items[].scopes[].name string 名稱
     * @api-response-field data.items[].scopes[].description string 說明
     */
    public function appScopes(FactoryAppService $service)
    {
        return $this->success(['items' => $service->scopesGroupedByModule()]);
    }

    private function operator(Request $request): ?string
    {
        return $this->currentUser($request)?->username;
    }

    // 絕不輸出 app_secret
    private function present(FactoryApp $app): array
    {
        return [
            'id' => $app->id,
            'app_id' => $app->app_id,
            'name' => $app->name,
            'status' => $app->status,
            'is_debug' => $app->is_debug,
            'allowed_ips' => $app->allowed_ips ?? [],
            'expired_at' => $app->expired_at?->toDateTimeString(),
            'remark' => $app->remark,
            'last_used_at' => $app->last_used_at?->toDateTimeString(),
            'scopes' => $app->scopeRows->pluck('scope')->values()->all(),
        ];
    }
}
