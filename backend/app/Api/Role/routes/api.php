<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色管理，見 README 7.1、7.2、7.4。
***************************************** */

use App\Api\Role\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// 角色管理，見 README 7.1、7.2、7.4。只掛 jwt.auth，不掛 permission.api：
// 這幾支動作可能跨端口操作（目標端口不一定等於登入 session 的端口，見
// BaseApiController::resolveTargetPortalId／authorizeOnPortal 開頭的完整
// 說明），middleware 只認得「目前登入 session 端口」，沒辦法處理跨端口
// 情境，改成 Controller 內部解析出目標端口後才判斷權限。
Route::middleware('jwt.auth')->group(function () {
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/permission-catalog', [RoleController::class, 'permissionCatalog']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{role}', [RoleController::class, 'update']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
});
