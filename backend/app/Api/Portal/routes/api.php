<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 端口管理，見 README 9.1。
***************************************** */

use App\Api\Portal\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

// 端口管理，見 README 9.1。不分端口，見 PortalController 開頭說明，直接用
// permission.api:{code} 檢查目前登入端口是否持有權限即可。
//
// public 這支刻意不掛 jwt.auth：子站 SPA 啟動、登入頁渲染之前就要呼叫
// （見 resources/vue_src_admin/src/main.js），比照 app/Api/Site/routes/api.php
// 的 GET /api/site/config 寫法。
Route::get('/portals/public/{portal_key}', [PortalController::class, 'publicInfo']);

Route::middleware('jwt.auth')->group(function () {
    Route::middleware('permission.api:api.portal.index')->get('/portals', [PortalController::class, 'index']);
    Route::middleware('permission.api:api.portal.store')->post('/portals', [PortalController::class, 'store']);
    Route::middleware('permission.api:api.portal.update')->put('/portals/{portal}', [PortalController::class, 'update']);
});
