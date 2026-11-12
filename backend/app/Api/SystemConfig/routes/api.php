<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 系統環境配置管理，見 README 17。
***************************************** */

use App\Api\SystemConfig\Controllers\SystemConfigController;
use Illuminate\Support\Facades\Route;

// 系統環境配置管理，見 README 17。不分端口，見 SystemConfigController 開頭
// 說明，直接用 permission.api:{code} 檢查目前登入端口是否持有權限即可。
Route::middleware('jwt.auth')->group(function () {
    Route::middleware('permission.api:api.system_config.index')->get('/system-configs', [SystemConfigController::class, 'index']);
    // batch 路由必須排在 {system_config} 參數路由之前，否則 "batch" 這個
    // 字面路徑會先被 {system_config} 這個沒有數字限制的參數比對走。
    Route::middleware('permission.api:api.system_config.batchUpdate')->put('/system-configs/batch', [SystemConfigController::class, 'batchUpdate']);
    Route::middleware('permission.api:api.system_config.update')->put('/system-configs/{system_config}', [SystemConfigController::class, 'update']);
    // image 系列路由帶固定字面後綴（/image、/image/crop、/image/original），
    // 不會被上面的 {system_config} 參數路由攔截，順序上不受影響，見 17.5。
    Route::middleware('permission.api:api.system_config.uploadImage')->post('/system-configs/{system_config}/image', [SystemConfigController::class, 'uploadImage']);
    Route::middleware('permission.api:api.system_config.cropImage')->put('/system-configs/{system_config}/image/crop', [SystemConfigController::class, 'cropImage']);
    Route::middleware('permission.api:api.system_config.originalImage')->get('/system-configs/{system_config}/image/original', [SystemConfigController::class, 'originalImage']);
});
