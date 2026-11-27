<?php

/* *****************************************
File: web.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 9 節：app/Web/{portal}/{module}/routes/web.php 各模組自己的路由， 統一在這裡掃描載入，見 routes/api.php 的同款做法。
***************************************** */

use App\Support\Http\Controllers\AdminSpaController;
use App\Support\Http\Controllers\CompanySpaController;
use App\Support\Http\Controllers\ApiDocsController;
use App\Support\Http\Controllers\HqSpaController;
use App\Support\Http\Controllers\SpaFallbackController;
use App\Support\Http\Middleware\DocsSecurityHeaders;
use App\Support\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

// 見 README 9 節：app/Web/{portal}/{module}/routes/web.php 各模組自己的路由，
// 統一在這裡掃描載入，見 routes/api.php 的同款做法。
foreach (glob(app_path('Web/*/*/routes/web.php')) as $moduleRoutes) {
    require $moduleRoutes;
}

// Swagger UI，見 README 12.1、12.7、4.2.1 第 6 點：CSP 放寬僅限這兩條路由，
// 用 withoutMiddleware 移除全域 SecurityHeaders、改掛獨立的 DocsSecurityHeaders，
// 不讓整站 CSP 因此變寬鬆。
Route::middleware(DocsSecurityHeaders::class)->withoutMiddleware(SecurityHeaders::class)->group(function () {
    Route::get('/docs', [ApiDocsController::class, 'internal']);
    Route::get('/docs/partner', [ApiDocsController::class, 'partner']);
    Route::get('/docs/hq', [ApiDocsController::class, 'hq']);
    Route::get('/docs/openapi.json', [ApiDocsController::class, 'internalSpec']);
    Route::get('/docs/openapi.partner.json', [ApiDocsController::class, 'partnerSpec']);
    Route::get('/docs/openapi.hq.json', [ApiDocsController::class, 'hqSpec']);
});

// 主站後台 Vue3 SPA 入口頁，見 README 6.12。不掛 site.context（主站沒有
// 多租戶概念），瀏覽器內的 Vue Router 接手處理 /hq/* 底下的子路徑，這裡
// 兩條路由只負責在真正命中時回傳同一份編譯產物首頁。放在子站 SPA 的
// fallback 路由之前註冊，避免歧義（雖然 /hq、/hq/{any} 已經是明確路由，
// 本來就會優先於 fallback 被匹配，這裡刻意寫在前面純粹是閱讀順序考量）。
Route::get('/hq', HqSpaController::class);
Route::get('/hq/{any}', HqSpaController::class)->where('any', '.*');

// 子站管理後台 Vue3 SPA 入口頁，見 README 7.2。同時支援兩種進站方式，
// 對應 6.1 的「網域優先、路徑前綴其次」：
//   - /admin、/admin/{any}：網域已綁定的子站（或用預設 1 號站）直接用這個路徑
//   - /{sitePrefix}/admin、/{sitePrefix}/admin/{any}：還沒綁網域、只能用路徑
//     前綴進站的子站，網址第一段先帶子站代碼，SiteResolver 判斷邏輯完全不用
//     改（照樣讀 $request->segment(1)，跟路由參數怎麼命名無關）
// 要掛 site.context 才能讓 SiteResolver 正確判斷出目前是哪個子站（跟前台
// fallback 掛法一致），不像主站 /hq 是完全獨立、不分子站的系統。
Route::middleware('site.context')->group(function () {
    Route::get('/admin', AdminSpaController::class);
    Route::get('/admin/{any}', AdminSpaController::class)->where('any', '.*');
    Route::get('/{sitePrefix}/admin', AdminSpaController::class)->where('sitePrefix', '[a-z0-9_]+');
    Route::get('/{sitePrefix}/admin/{any}', AdminSpaController::class)->where(['sitePrefix' => '[a-z0-9_]+', 'any' => '.*']);

    // 公司端口（訪客模組各公司帳號登入，見 README 5.4 新增端口）：網址結構同管理後台。
    Route::get('/company', CompanySpaController::class);
    Route::get('/company/{any}', CompanySpaController::class)->where('any', '.*');
    Route::get('/{sitePrefix}/company', CompanySpaController::class)->where('sitePrefix', '[a-z0-9_]+');
    Route::get('/{sitePrefix}/company/{any}', CompanySpaController::class)->where(['sitePrefix' => '[a-z0-9_]+', 'any' => '.*']);
});

// 見 README 3.2 第 3 點：其餘前台 GET 路徑都回傳 Vue 入口頁，掛 site.context
// 才能判斷目前站點屬於哪個 vue_build_mode（見 8.1）。這條必須放最後，
// Laravel 的具名/前綴路由（含 routes/api.php 的 /api/*、上方模組路由）
// 本來就會優先於 fallback 被匹配，不需要額外排除。
Route::fallback(SpaFallbackController::class)->middleware('site.context');
