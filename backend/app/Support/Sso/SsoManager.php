<?php

/* *****************************************
File: SsoManager.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 依 driver key 解析出實際的對接實作，見 README 8.6。
***************************************** */

namespace App\Support\Sso;

use App\Support\Sso\Contracts\SsoProvider;
use RuntimeException;

// 依 driver key 解析出實際的對接實作，見 README 8.6。key 對應的類別清單
// 是程式碼層級的註冊表（config/sso.php），實際「這個站要用哪一個 key」
// 是案場資料，存在 system_configs，兩者分開，換案場對接方式不需要改代碼。
class SsoManager
{
    public function driver(string $key): SsoProvider
    {
        $class = config("sso.drivers.{$key}");

        if (! $class) {
            throw new RuntimeException(__('api.sso.driver_not_registered', ['key' => $key]));
        }

        if (! is_subclass_of($class, SsoProvider::class)) {
            throw new RuntimeException(__('api.sso.driver_not_implementing_interface', ['class' => $class, 'interface' => SsoProvider::class]));
        }

        return app($class);
    }
}
