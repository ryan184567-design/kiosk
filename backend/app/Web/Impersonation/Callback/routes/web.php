<?php

/* *****************************************
File: web.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-14
Description: 見 DEVELOP.MD 6.11 第 2 點：主站跳轉登入的子站端落地頁路由。
***************************************** */

use App\Web\Impersonation\Callback\Controllers\HqImpersonationCallbackController;
use Illuminate\Support\Facades\Route;

// 見 DEVELOP.MD 6.11 第 2 點：主站前端導向子站的落地頁，掛 site.context
// 才知道這次請求落在哪個子站（用來比對交換碼綁定的站點，見 6.11 第 3 點）。
//
// 兩種進站方式都要能到達這一頁（與 routes/web.php 的 /admin 註冊方式一致，
// 見 6.1「網域優先 → 路徑前綴其次」）：
//   - /hq-impersonate/callback：網域已綁定的子站（或用預設 1 號站）
//   - /{sitePrefix}/hq-impersonate/callback：只能用路徑前綴進站的子站
// 放在模組自己的 routes/web.php（由 routes/web.php 的 glob 統一載入），
// 與 8.6 的 /sso/callback 同一個慣例：這支不綁定特定端口，目標端口由交換碼
// 內的 portal_key 決定，不是靠路由路徑判斷。
Route::middleware('site.context')->group(function () {
    Route::get('/hq-impersonate/callback', [HqImpersonationCallbackController::class, 'callback'])
        ->name('hq-impersonate.callback');

    Route::get('/{sitePrefix}/hq-impersonate/callback', [HqImpersonationCallbackController::class, 'callback'])
        ->where('sitePrefix', '[a-z0-9_]+');
});
