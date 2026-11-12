<?php

/* *****************************************
File: PluginAdminService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 插件管理業務邏輯，見 README 6.10、17.4。
***************************************** */

namespace App\Api\Plugin\Services;

use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\SystemConfig\Services\SystemConfigService;
use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Plugin\Models\SitePluginConfig;
use App\Support\Plugin\PluginRegistry;
use RuntimeException;

// 插件管理業務邏輯，見 README 6.10、17.4。Controller 只做驗證/轉發
// （4 節第 3 點）。目前並存兩種插件機制（見 PluginRegistry 開頭說明）：
//   - connector：6.10 標準插件骨架（AD 這類），啟用狀態存
//     site_plugin_configs（一個 function/slot 同時只會有一個品牌/版本
//     是啟用中的）。
//   - sso_driver：既有的 SSO driver registry（config/sso.php），啟用狀態
//     沿用 system_configs 裡的 sso_enabled／sso_driver 兩個既有欄位，
//     不是 site_plugin_configs。
class PluginAdminService
{
    private const FUNCTION_LABELS = [
        'ad' => 'AD／目錄驗證',
        'sso' => 'SSO 單一登入',
    ];

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly SystemConfigService $configs,
    ) {}

    public function catalog(): array
    {
        $result = [];

        foreach ($this->registry->scanConnectors() as $function => $entries) {
            $activeSlot = SitePluginConfig::query()
                ->where('function_code', $function)
                ->where('slot', 'default')
                ->first();

            $result[] = [
                'function' => $function,
                'function_name' => self::FUNCTION_LABELS[$function] ?? $function,
                'mechanism' => 'connector',
                'entries' => array_map(fn (array $entry) => [
                    ...$entry,
                    'is_enabled' => (bool) ($activeSlot && $activeSlot->brand_version_key === $entry['key'] && $activeSlot->is_enabled),
                ], $entries),
            ];
        }

        $ssoEntries = $this->registry->ssoDrivers();

        if ($ssoEntries !== []) {
            $ssoValues = $this->configs->valuesForModule('sso');
            $currentDriver = $ssoValues['sso_driver'] ?? null;
            $ssoEnabled = ($ssoValues['sso_enabled'] ?? '0') === '1';

            $result[] = [
                'function' => 'sso',
                'function_name' => self::FUNCTION_LABELS['sso'],
                'mechanism' => 'sso_driver',
                'entries' => array_map(fn (array $entry) => [
                    ...$entry,
                    'is_enabled' => $ssoEnabled && $currentDriver === $entry['key'],
                ], $ssoEntries),
            ];
        }

        return $result;
    }

    // 啟用一個插件：先確保它的設定項存在（見 ensureConfigSeeded，只在
    // 「第一次啟用」或「schema 有新增欄位」時真正寫入，不會覆蓋已經填過
    // 的值），再依機制切換啟用狀態。connector 類型會把同一個 function/slot
    // 底下原本啟用的其他品牌/版本自動換掉（同一時間只能有一個生效，見
    // PluginManager::resolve 的語意）。
    public function enable(User $actor, string $function, string $key, ?string $ip = null): void
    {
        $entry = $this->findEntry($function, $key);

        if (! $entry['is_registered']) {
            throw new RuntimeException(__('api.plugin.not_registered_in_config', ['key' => "{$function}.{$key}"]));
        }

        $this->ensureConfigSeeded($entry['config_module'], $entry['schema']);

        if ($function === 'sso') {
            $this->setSsoDriver($actor, $key, true, $ip);
        } else {
            SitePluginConfig::query()->updateOrCreate(
                ['function_code' => $function, 'slot' => 'default'],
                ['brand_version_key' => $key, 'is_enabled' => true]
            );
        }

        $this->audit($actor, 'plugin.enable', $function, $key, $ip);
    }

    public function disable(User $actor, string $function, string $key, ?string $ip = null): void
    {
        if ($function === 'sso') {
            $this->setSsoDriver($actor, $key, false, $ip);
        } else {
            SitePluginConfig::query()
                ->where('function_code', $function)
                ->where('slot', 'default')
                ->where('brand_version_key', $key)
                ->update(['is_enabled' => false]);
        }

        $this->audit($actor, 'plugin.disable', $function, $key, $ip);
    }

    // 見類別開頭說明：schema 只在「還沒有這個設定項」時才寫入預設值，
    // 已存在的設定項只同步 title/type/option 這些描述性欄位，cfg_value
    // 一律保留原樣——啟用/停用是可以重複操作的（例如先啟用填好設定、
    // 停用、之後再啟用），不能每次都把使用者填過的值洗回預設值。
    private function ensureConfigSeeded(string $module, array $schema): void
    {
        $sort = 0;

        foreach ($schema as $name => $definition) {
            $existing = SystemConfig::query()->where('cfg_module', $module)->where('cfg_name', $name)->first();

            if ($existing) {
                $existing->update([
                    'cfg_title' => $definition['description'] ?? $name,
                    'cfg_type' => $definition['type'] ?? 'string',
                    'cfg_option' => $definition['option'] ?? null,
                    'sort' => $sort,
                ]);
            } else {
                SystemConfig::query()->create([
                    'cfg_module' => $module,
                    'cfg_name' => $name,
                    'cfg_title' => $definition['description'] ?? $name,
                    'cfg_type' => $definition['type'] ?? 'string',
                    'cfg_option' => $definition['option'] ?? null,
                    'cfg_value' => $definition['default'] ?? '',
                    'sort' => $sort,
                ]);
            }

            $sort++;
        }
    }

    private function setSsoDriver(User $actor, string $key, bool $enabled, ?string $ip): void
    {
        $driverConfig = SystemConfig::query()->where('cfg_module', 'sso')->where('cfg_name', 'sso_driver')->first();
        $enabledConfig = SystemConfig::query()->where('cfg_module', 'sso')->where('cfg_name', 'sso_enabled')->first();

        if ($driverConfig) {
            $this->configs->updateValue($actor, $driverConfig, $key, $ip);
        }

        if ($enabledConfig) {
            $this->configs->updateValue($actor, $enabledConfig, $enabled ? '1' : '0', $ip);
        }
    }

    private function findEntry(string $function, string $key): array
    {
        $entries = $function === 'sso'
            ? $this->registry->ssoDrivers()
            : ($this->registry->scanConnectors()[$function] ?? []);

        foreach ($entries as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        throw new RuntimeException(__('api.plugin.entry_not_found', ['function' => $function, 'key' => $key]));
    }

    private function audit(User $actor, string $action, string $function, string $key, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => "plugin:{$function}:{$key}",
            'context' => ['function' => $function, 'key' => $key],
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
