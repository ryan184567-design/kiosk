<?php

/* *****************************************
File: factory.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App（機器）認證設定，見 docs/APP_DEVELOP.MD 第 8 節。
***************************************** */

return [

    // JWT 簽名密鑰，獨立於 APP_KEY、JWT_SITE_SECRET、JWT_HQ_SECRET，必填。
    'jwt_secret' => env('FACTORY_JWT_SECRET'),

    'jwt_issuer' => env('FACTORY_JWT_ISSUER', 'factory-api'),

    // access token 有效秒數
    'jwt_ttl' => (int) env('FACTORY_JWT_TTL', 3600),

    // 簽名 timestamp 容許偏差秒數
    'timestamp_window' => (int) env('FACTORY_TIMESTAMP_WINDOW', 300),

    // nonce 快取秒數，需大於 timestamp_window
    'nonce_ttl' => (int) env('FACTORY_NONCE_TTL', 600),

    // App 授權 scope 的快取秒數（停用／過期不快取，每次請求即時判斷）
    'scope_cache_ttl' => (int) env('FACTORY_SCOPE_CACHE_TTL', 60),

    // 簽發接口限流（每分鐘）
    'token_rate_per_ip' => (int) env('FACTORY_TOKEN_RATE_PER_IP', 30),
    'token_rate_per_app' => (int) env('FACTORY_TOKEN_RATE_PER_APP', 20),

    // 呼叫記錄（factory_logs.type = 'call'）：App 的除錯模式開啟時每次呼叫都記錄，關閉時只記錄非 2xx。
    // 每個區塊（headers、query、json、form、回應內容…）各自的最大位元組數，超過會截斷並標註，避免大量資料撐爆資料庫。
    'log_max_bytes' => (int) env('FACTORY_LOG_MAX_BYTES', 65536),

    // 記錄保留天數：超過就自動移除（factory_logs 全部類型，依 created_at），由每天排程的 `factory:logs-prune` 執行。
    'log_retention_days' => (int) env('FACTORY_LOG_RETENTION_DAYS', 30),

    // 自動清理：是否啟用、每天幾點執行（需 cron 每分鐘跑 `php artisan schedule:run`，DEVELOP.MD 18.4）
    'log_prune_enabled' => (bool) env('FACTORY_LOG_PRUNE_ENABLED', true),
    'log_prune_time' => env('FACTORY_LOG_PRUNE_TIME', '03:40'),
];
