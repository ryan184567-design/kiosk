<?php

/* *****************************************
File: plugins.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 插件註冊清單，見 README 6.10.2。
***************************************** */

// 插件註冊清單，見 README 6.10.2。唯一事實來源：這裡列出程式碼庫實際
// 存在的插件，不重複維護在資料庫。key 命名規則見 6.10.2：
// {brand的snake_case}_{version}，例如 microsoft_v2019。
//
// 新增插件用 `php artisan plugin:make {function} {brand} {version}`（見 18 第 8 點），
// 指令會提示把產生的類別登記到這裡。

return [

    // 見 README 6.10：驗證範例插件（ad/ldap/v1），示範整條插件系統鏈路，
    // 用 PHP 內建 ldap 擴充套件實作，見 app/Plugins/Ad/Ldap/V1/AdConnector.php。
    'ad' => [
        'ldap_v1' => \App\Plugins\Ad\Ldap\V1\AdConnector::class,
        // 'microsoft_v2019' => \App\Plugins\Ad\Microsoft\V2019\AdConnector::class,
    ],

    // 'sso' => [
    //     'okta_v1' => \App\Plugins\Sso\Okta\V1\SsoConnector::class,
    // ],

];
