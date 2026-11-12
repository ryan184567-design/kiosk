<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單管理，見 README 7.3。
***************************************** */

use App\Api\Menu\Controllers\MenuController;
use Illuminate\Support\Facades\Route;

// 選單管理，見 README 7.3。不掛 permission.api：可能跨端口操作，且
// admin_only 端口有額外的把關規則，一律在 Controller 內部判斷
// （見 MenuController::authorizeMenuManagement，理由同 RoleController）。
Route::middleware('jwt.auth')->group(function () {
    Route::get('/menus', [MenuController::class, 'index']);
    // modules／portals／reorder 必須排在 {menu} 參數路由之前，否則這些字面
    // 路徑會先被 {menu} 這個沒有數字限制的參數比對走。
    Route::get('/menus/portals', [MenuController::class, 'portals']);
    Route::get('/menus/modules', [MenuController::class, 'modules']);
    Route::post('/menus', [MenuController::class, 'store']);
    Route::put('/menus/reorder', [MenuController::class, 'reorder']);
    Route::put('/menus/{menu}', [MenuController::class, 'update']);
    Route::delete('/menus/{menu}', [MenuController::class, 'destroy']);
});
