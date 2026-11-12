<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站路由（app/Api、app/Web），一律掛 site.context 才能存取 site_current 連線， 見 README 6.4、6.5。
***************************************** */

use Illuminate\Support\Facades\Route;

// 子站路由（app/Api、app/Web），一律掛 site.context 才能存取 site_current 連線，
// 見 README 6.4、6.5。每個模塊自己的 routes/api.php 由 module:make 建立
// （見 18 第 4 點），這裡統一掃描載入，不需要每加一個模塊就手動註冊一次。
Route::middleware('site.context')->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => true, 'error_code' => 0, 'data' => ['pong' => true]]));

    foreach (glob(app_path('Api/*/routes/api.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});

// 主站路由（app/Hq），完全不經過 site.context——用預設 mysql 連線，
// 與子站程式碼、資料庫、權限體系物理隔離，見 6.5。模塊層結構、掃描方式
// 比照上方 app/Api 的做法（見 README 9 節目錄樹），不用每加一個模塊就手動
// 註冊一次。
Route::prefix('hq')->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => true, 'error_code' => 0, 'data' => ['pong' => true]]));

    foreach (glob(app_path('Hq/*/routes/api.php')) as $moduleRoutes) {
        require $moduleRoutes;
    }
});
