<?php

/* *****************************************
File: SitePluginConfig.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站插件設定，見 README 6.10.2。
***************************************** */

namespace App\Support\Plugin\Models;

use Illuminate\Database\Eloquent\Model;

// 子站插件設定，見 README 6.10.2。config 欄位加密儲存。
class SitePluginConfig extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'function_code',
        'slot',
        'brand_version_key',
        'config',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_enabled' => 'boolean',
        ];
    }
}
