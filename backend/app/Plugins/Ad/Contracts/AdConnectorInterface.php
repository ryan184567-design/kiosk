<?php

/* *****************************************
File: AdConnectorInterface.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 統一介面，見 README 6.10.1：所有品牌/版本的實作都必須實作這個介面， 呼叫端永遠依賴介面與 DTO，不依賴任何品牌專屬類別，換插件不用改業務程式碼。
***************************************** */

namespace App\Plugins\Ad\Contracts;

// 統一介面，見 README 6.10.1：所有品牌/版本的實作都必須實作這個介面，
// 呼叫端永遠依賴介面與 DTO，不依賴任何品牌專屬類別，換插件不用改業務程式碼。
interface AdConnectorInterface
{
    /**
     * @param  array{username: string, password: string}  $credentials
     * @return array{external_id: string, username: string, name: string, email: ?string} 驗證通過的帳號基本資料
     *
     * @throws \App\Support\Plugin\PluginConnectorException 帳密錯誤、連線失敗、回應格式異常，見 6.10.3 的統一例外分類
     */
    public function authenticate(array $credentials): array;

    /**
     * 見 README 6.10.4／17.4：插件管理頁面用這份 schema 把設定項自動種進
     * system_configs（cfg_module 見 PluginRegistry::moduleCodeFor），複用
     * 系統環境配置既有的型別／驗證／編輯 UI（SystemConfigEditor.vue），
     * 插件不用自己刻一份設定表單，也不用自己驗證。
     *
     * @return array<string, array{
     *     type: string,
     *     required: bool,
     *     description: string,
     *     option?: mixed,
     *     default?: string,
     * }> 鍵是 cfg_name；`type` 為 \App\Api\SystemConfig\Models\SystemConfig::TYPES
     *    其中之一（string/int/bool/password/url/enum/json…）；`option` 依
     *    type 而定（enum 是選項陣列、string 是最大長度…，規則同 17.1）；
     *    `default` 省略時視為空字串；`required` 目前僅供文件說明用途，
     *    尚未接到 SystemConfigService 的驗證規則（見 6.10.7 已知限制）。
     */
    public static function configSchema(): array;
}
