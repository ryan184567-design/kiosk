<?php

/* *****************************************
File: FactoryScopeMatcher.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: scope 授權判斷（精確／模塊通配／全部），見 docs/APP_DEVELOP.MD 5 節「Scope 命名」
***************************************** */

namespace App\Support\Factory;

use App\Api\Factory\Models\FactoryApp;
use Illuminate\Support\Facades\Cache;

class FactoryScopeMatcher
{
    /** 已授權的 scope 清單（短暫快取，授權變更時請呼叫 forget） */
    public function granted(FactoryApp $app): array
    {
        return Cache::remember(self::cacheKey($app->id), (int) config('factory.scope_cache_ttl'),
            fn () => $app->scopeRows()->pluck('scope')->all());
    }

    public static function forget(int $appId): void
    {
        Cache::forget(self::cacheKey($appId));
    }

    public function allows(array $granted, string $required): bool
    {
        if (in_array('*', $granted, true) || in_array($required, $granted, true)) {
            return true;
        }

        $dot = strpos($required, '.');

        return $dot !== false && in_array(substr($required, 0, $dot).'.*', $granted, true);
    }

    private static function cacheKey(int $appId): string
    {
        return "factory_app_scopes:{$appId}";
    }
}
