<?php

/* *****************************************
File: PluginManager.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.10.2。
***************************************** */

namespace App\Support\Plugin;

use App\Api\SystemConfig\Services\SystemConfigService;
use App\Support\Plugin\Models\SitePluginConfig;
use InvalidArgumentException;
use RuntimeException;

// 見 README 6.10.2、17.4。呼叫端統一寫法：
// PluginManager::resolve('ad')->authenticate(...)，不需要知道目前案場實際
// 用的是哪個品牌/版本。site_plugin_configs 只保留「這個 function/slot 現在
// 啟用哪個品牌/版本」的選擇本身（is_enabled/brand_version_key），實際設定
// 值改存在 system_configs（見 PluginAdminService::ensureConfigSeeded），
// 複用系統環境配置既有的型別驗證與密碼加密／遮罩機制，不用另外維護一份
// 加密邏輯——這裡組裝 connector 建構參數時去讀 system_configs，不是直接
// 讀 site_plugin_configs.config 這個欄位（該欄位保留給尚未走這條新流程的
// 舊資料相容，實際不再寫入）。
class PluginManager
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly SystemConfigService $configs,
    ) {}

    public function resolve(string $function, string $slot = 'default'): object
    {
        $pluginConfig = SitePluginConfig::query()
            ->where('function_code', $function)
            ->where('slot', $slot)
            ->where('is_enabled', true)
            ->first();

        if (! $pluginConfig) {
            throw new RuntimeException(__('api.plugin.not_configured', ['function' => $function, 'slot' => $slot]));
        }

        $class = config("plugins.{$function}.{$pluginConfig->brand_version_key}");

        if (! $class) {
            // 見 6.10.2 第 3 點：找不到就明確報錯中斷，不可靜默回傳 null。
            throw new InvalidArgumentException(
                __('api.plugin.not_registered_in_config', ['key' => "{$function}.{$pluginConfig->brand_version_key}"])
            );
        }

        $module = $this->registry->moduleCodeFor($function, $pluginConfig->brand_version_key);
        $values = $this->configs->valuesForModule($module);

        return app()->makeWith($class, ['config' => $values]);
    }
}
