<?php

/* *****************************************
File: cors.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 4.2.2：一開始就用明確網域白名單，不使用 `*`；本機開發網域也 要明確列出，不因為是本機就整組放行。
***************************************** */

// 見 README 4.2.2：一開始就用明確網域白名單，不使用 `*`；本機開發網域也
// 要明確列出，不因為是本機就整組放行。
//
// 背景說明：本專案的 Vue3 SPA／Blade 頁面預設與 API 同來源（同一個
// Laravel 應用直接提供，見 3.2、8 節），瀏覽器呼叫 `/api/*` 正常情況下
// 本來就是同源請求，根本不會觸發 CORS 檢查。本機開發用 `npm run vue:dev`
// 時 SPA 由 Vite 自己的 dev server 提供（跟 API 不同 origin），但解法是
// `vite.vue.config.js` 的 dev server proxy（把 /api 轉發到真正的後端，
// 讓瀏覽器完全感受不到跨來源），**不是**把 dev server 塞進這份 CORS
// 白名單——proxy 從瀏覽器的角度看本來就是同源，比開 CORS 更乾淨、也不用
// 處理「dev server port 衝突時自動換掉」這種問題（見 4.2.1 CSP 那邊為了
// 這個原因才需要動態讀 hot 檔案，CORS 這裡不需要對應機制）。
//
// 這份白名單只保留給「前台真的架在跟 API 不同網域」這種少數情境（非本
// 階段預設架構，見 9.1）：正式環境的白名單一律用 `CORS_ALLOWED_ORIGINS`
// （逗號分隔）明確列出，預設空陣列——沒設定就是「不允許任何跨來源請求」，
// 嚴格優先、及早失敗，不會因為忘記設定就悄悄放行所有來源。
//
// 若日後真的需要「依子站網域動態決定 CORS 白名單」（例如子站前台真的架在
// 跟 API 不同的網域），因為 `storage/app/sites/sites.json` 的網域清單
// 設計上是 immediate 生效、不需要 `config:cache`（見 6.4），直接讀進這份
// 會被 `config:cache` 凍結的設定檔會違背那個設計；屆時應該另外寫一支
// 自訂 middleware 動態比對 sites.json，取代這裡靜態的 `allowed_origins`
// 判斷。目前架構下沒有這個真實需求，故不預先實作。

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // 實測發現底層 fruitcake/php-cors 套件的一個行為：清單裡剛好只有一個
    // 來源時，不會真的比對請求的 Origin 是否相符，一律直接回傳這一個
    // 設定值（清單有兩個以上時比對邏輯才會正確生效，已實測驗證兩種情況）。
    // 這不是可利用的安全漏洞——CORS 的實際防護是瀏覽器端比對回應的
    // Access-Control-Allow-Origin 是否等於「頁面自己的來源」，惡意網站
    // 收到一個不等於自己來源的值一樣會被瀏覽器擋下，讀不到回應內容；
    // 但如果日後真的只會設定一個網域，這裡的行為看起來像「來源比對」，
    // 實際上是「固定回傳」，日後排查/稽核時容易誤解，先在這裡記錄清楚。
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // JWT 走 Authorization: Bearer header（見 11.1），不靠 cookie 傳遞
    // 跨來源憑證；8.6 SSO 的 cookie 是同源導頁情境，不涉及跨來源請求，
    // 不需要開啟 credentials。
    'supports_credentials' => false,

];
