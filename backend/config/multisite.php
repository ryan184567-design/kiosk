<?php

/* *****************************************
File: multisite.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 多租戶（多站）設定，見 README 6.1、6.3。
***************************************** */

// 多租戶（多站）設定，見 README 6.1、6.3。

return [

    'enabled' => env('MULTI_SITE_ENABLED', true),

    // 子站資料庫命名前綴，見 6.3：資料庫名固定為 {prefix}{site.code}
    // （例如 code=vendora → base_site_vendora），方便直接從資料庫名對照
    // 是哪個子站，不再用 site_id 編號；前綴可設定是為了跟同一台 MySQL上
    // 其他不相關的資料庫（例如舊專案）明確區隔，避免裸用 code 當資料庫名
    // 在共用 MySQL 環境下互相撞名。
    'site_db_prefix' => env('SITE_DB_PREFIX', 'base_site_'),

    // 單站模式（MULTI_SITE_ENABLED=false，見 README 6.1、6.8）固定使用的站點識別碼。
    // 這兩個值一定要走 config、不能在程式碼裡用 env() 讀：`php artisan config:cache`
    // 之後 Laravel 不會載入 .env，執行期的 env() 一律回傳 null——單站部署包實際
    // 踩過，症狀是 site_current 連線變成「空帳號、空資料庫」，登入直接
    // `SQLSTATE[HY000] [1045] Access denied for user ''@'localhost'`。
    'single_site' => [
        // 這個部署包固化的站點編號（由 site:package 寫入原本在多站環境下的
        // site_id，不是固定 1），用來對應資料庫內以 site_id 標記的既有資料。
        'site_id' => (int) env('SITE_ID', 1),

        // 客製化覆寫的識別碼（預設等於站點代碼），見 6.8、9.2。
        'template_namespace' => (string) env('SITE_TEMPLATE_NAMESPACE', 'default'),
    ],

];
