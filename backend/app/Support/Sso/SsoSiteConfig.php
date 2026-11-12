<?php

/* *****************************************
File: SsoSiteConfig.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 把該站 system_configs（cfg_module=sso，見 17 節）讀成一份型別明確的設定陣列， 集中在這一處，SsoLoginService／各驅動都呼叫這裡取值，不要各自寫一份 SystemConfig 查詢邏輯散落各處（見開發約定「共用能力不得重複散落」）。
***************************************** */

namespace App\Support\Sso;

use App\Api\SystemConfig\Models\SystemConfig;

// 把該站 system_configs（cfg_module=sso，見 17 節）讀成一份型別明確的設定陣列，
// 集中在這一處，SsoLoginService／各驅動都呼叫這裡取值，不要各自寫一份
// SystemConfig 查詢邏輯散落各處（見開發約定「共用能力不得重複散落」）。
class SsoSiteConfig
{
    private const BOOL_KEYS = ['sso_enabled', 'sso_auto_create_user'];

    private const JSON_KEYS = ['sso_field_mapping'];

    public function load(): array
    {
        $rows = SystemConfig::query()
            ->where('cfg_module', 'sso')
            ->pluck('cfg_value', 'cfg_name');

        $config = [];

        foreach ($rows as $name => $value) {
            if (in_array($name, self::BOOL_KEYS, true)) {
                $config[$name] = $value === '1';
            } elseif (in_array($name, self::JSON_KEYS, true)) {
                $config[$name] = $value ? (json_decode($value, true) ?: []) : [];
            } else {
                $config[$name] = $value;
            }
        }

        return $config;
    }
}
