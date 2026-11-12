<?php

/* *****************************************
File: PluginController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 插件管理，見 README 6.10、17.4。
***************************************** */

namespace App\Api\Plugin\Controllers;

use App\Api\Plugin\Services\PluginAdminService;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 插件管理，見 README 6.10、17.4。不分端口（跟 SystemConfig 一樣是整站
// 層級的設定，不像 Role/User/Menu 綁定 portal_id），權限一律用路由層
// permission.api:{code} 檢查。
class PluginController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/plugins
     * @api-tag Plugin
     * @api-summary 列出插件目錄與目前啟用狀態
     * @api-description
     * 目錄本身（有哪些 function／品牌／版本）掃描自 `app/Plugins` 目錄與
     * `config/sso.php`（見 `PluginRegistry`），不分站；每筆額外附這個
     * 站目前的啟用狀態與設定模組代碼（`config_module`）。點進某個插件的
     * 設定，前端直接掛一個 `SystemConfigEditor`（見 README 17.4）指到
     * `config_module`，複用系統環境配置既有的型別／驗證／編輯 UI，不用
     * 為插件另外刻表單。
     * @api-portal *
     * @api-response-field data[].function string 功能分類代碼，例如 ad、sso
     * @api-response-field data[].function_name string 功能分類名稱
     * @api-response-field data[].mechanism dict:plugin_mechanism 這個分類走哪套啟用機制，決定 enable/disable 的語意
     * @api-response-field data[].entries[].key string 品牌/版本鍵值，例如 ldap_v1
     * @api-response-field data[].entries[].brand string 品牌名稱（sso_driver 機制無此欄位，為 null）
     * @api-response-field data[].entries[].version string 版本（sso_driver 機制無此欄位，為 null）
     * @api-response-field data[].entries[].class string 實作類別的完整命名空間
     * @api-response-field data[].entries[].is_registered bool 是否已登記在 config/plugins.php（或 config/sso.php），未登記無法啟用
     * @api-response-field data[].entries[].config_module string 設定值存放的 cfg_module，供 GET /api/system-configs?module= 查詢
     * @api-response-field data[].entries[].is_enabled bool 這個站目前是否啟用這個品牌/版本
     * @api-dict plugin_mechanism connector=6.10 標準插件骨架（site_plugin_configs 管理啟用狀態）,sso_driver=既有 SSO driver registry（system_configs 的 sso_enabled/sso_driver 管理啟用狀態）
     */
    public function index(PluginAdminService $plugins)
    {
        return $this->success($plugins->catalog());
    }

    /**
     * @api-method POST
     * @api-path /api/plugins/{function}/{key}/enable
     * @api-tag Plugin
     * @api-summary 啟用插件
     * @api-description
     * 第一次啟用時會自動把該插件 `configSchema()` 定義的設定項種進
     * `system_configs`（見 `PluginAdminService::ensureConfigSeeded`），
     * 已經填過的值不會被洗回預設值。`connector` 機制（如 AD）啟用某個
     * 品牌/版本，同一個 function 底下原本啟用的其他品牌/版本會被自動
     * 換掉（同一時間只有一個生效）；`sso_driver` 機制（SSO）啟用即等同
     * 把 `sso_driver` 設定切到這個鍵值、`sso_enabled` 設為開啟。未登記在
     * `config/plugins.php`／`config/sso.php` 的插件無法啟用。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function enable(Request $request, string $function, string $key, PluginAdminService $plugins)
    {
        try {
            $plugins->enable($this->currentUser($request), $function, $key, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }

    /**
     * @api-method PUT
     * @api-path /api/plugins/{function}/{key}/disable
     * @api-tag Plugin
     * @api-summary 停用插件
     * @api-description
     * 只關閉啟用狀態，已填寫的設定值原樣保留（`system_configs` 的資料列
     * 不會被刪除），之後重新啟用不用重填。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function disable(Request $request, string $function, string $key, PluginAdminService $plugins)
    {
        try {
            $plugins->disable($this->currentUser($request), $function, $key, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }
}
