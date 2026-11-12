<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 插件管理，見 README 6.10、17.4。
***************************************** */

use App\Api\Plugin\Controllers\PluginController;
use Illuminate\Support\Facades\Route;

// 插件管理，見 README 6.10、17.4。不分端口，權限一律用 permission.api:{code}
// 檢查目前登入端口是否持有對應權限，跟 SystemConfig 同一套邏輯（整站層級
// 設定，不像 Role/User/Menu 綁定 portal_id）。
Route::middleware('jwt.auth')->group(function () {
    Route::middleware('permission.api:api.plugin.index')->get('/plugins', [PluginController::class, 'index']);
    Route::middleware('permission.api:api.plugin.enable')->post('/plugins/{function}/{key}/enable', [PluginController::class, 'enable']);
    Route::middleware('permission.api:api.plugin.disable')->put('/plugins/{function}/{key}/disable', [PluginController::class, 'disable']);
});
