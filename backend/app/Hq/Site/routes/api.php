<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 站點管理，見 README 6.3、6.5。
***************************************** */

use App\Hq\Site\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// 站點管理，見 README 6.3、6.5。高風險操作一律要求 hq.super_admin。
Route::middleware(['hq.jwt.auth', 'hq.super_admin'])->group(function () {
    Route::get('/sites', [SiteController::class, 'index']);
    Route::get('/sites/{siteId}', [SiteController::class, 'show']);
    Route::post('/sites', [SiteController::class, 'store'])->middleware('throttle:site-create');
    Route::put('/sites/{siteId}/domain', [SiteController::class, 'updateDomain']);

    // 單站部署包輸出（見 6.8）。與 CLI site:package 共用 SitePackageService，
    // 後台只是提供「選站點→輸出」的操作介面；一個部署包少說幾千個檔案，加
    // 限流避免被連續點擊或迴圈呼叫灌爆磁碟。
    Route::post('/sites/{siteId}/package', [SiteController::class, 'package'])->middleware('throttle:site-package');
});
