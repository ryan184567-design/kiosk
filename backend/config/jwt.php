<?php

/* *****************************************
File: jwt.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: JWT 簽發設定，見 README 11.1。
***************************************** */

// JWT 簽發設定，見 README 11.1。主站與子站共用同一份演算法/TTL 設定，
// 但各自用不同的 secret（見下），彼此簽出的 token 不能互通。

return [

    'algo' => 'HS256',

    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 120),

    // 子站使用者 token 簽章密鑰。刻意獨立於 APP_KEY，因為 APP_KEY 還用於
    // 加密其他資料（見 6.10.2 的 encrypted:array cast），密鑰用途要分開。
    'site_secret' => env('JWT_SITE_SECRET'),

    // 主站使用者 token 簽章密鑰，見 6.12。與 site_secret 分開，避免子站
    // token 被拿去冒充主站身份（反之亦然）。
    'hq_secret' => env('JWT_HQ_SECRET'),

    // 見 README 8.6：Blade／SSO 落地頁用 cookie 承載子站 JWT（瀏覽器直接導頁
    // 不會帶 Authorization header），EnsureJwtAuthenticated 在沒有 bearer
    // token 時會 fallback 讀這個 cookie；Vue3 SPA／一般 API 呼叫走 bearer
    // header 為主，兩者互不影響。
    'cookie_name' => env('JWT_SITE_COOKIE_NAME', 'site_jwt'),

];
