<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 使用者／端口存取管理，見 README 7.1、7.2。
***************************************** */

use App\Api\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// 使用者／端口存取管理，見 README 7.1、7.2。權限碼是 api.{module}.{方法名稱}
// 直接取自 Controller method 名稱（camelCase，見 PermissionSyncApiCommand::syncOne），
// 這裡務必跟實際方法名稱一致，不要自己發明代碼。
//
// index/grantAccess/revokeAccess 可能跨端口操作（目標端口不一定等於登入
// session 的端口），不掛 permission.api，改成 Controller 內部解析出目標
// 端口後才判斷權限（見 BaseApiController::authorizeOnPortal、
// RoleController 開頭註解同一個理由）；search/identityTypeOptions/store/
// resetPassword 本來就不分端口，維持原本 middleware 寫法即可。
Route::middleware('jwt.auth')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::middleware('permission.api:api.user.search')->get('/users/search', [UserController::class, 'search']);
    Route::middleware('permission.api:api.user.identityTypeOptions')->get('/users/identity-type-options', [UserController::class, 'identityTypeOptions']);
    Route::middleware('permission.api:api.user.grantablePortals')->get('/users/grantable-portals', [UserController::class, 'grantablePortals']);
    Route::middleware('permission.api:api.user.store')->post('/users', [UserController::class, 'store']);
    Route::post('/users/{user}/portal-access', [UserController::class, 'grantAccess']);
    Route::delete('/users/{user}/portal-access', [UserController::class, 'revokeAccess']);
    Route::middleware('permission.api:api.user.resetPassword')->put('/users/{user}/password', [UserController::class, 'resetPassword']);
});
