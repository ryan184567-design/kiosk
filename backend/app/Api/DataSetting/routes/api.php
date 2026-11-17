<?php

use Illuminate\Support\Facades\Route;
use App\Api\DataSetting\Controllers\DataSettingController;

// 見 README 6.9：模塊路由需掛載 module:{code} middleware。
Route::middleware(['module:data_setting', 'jwt.auth'])->prefix('data-setting')->group(function () {
    Route::middleware('permission.api:api.data_setting.index')->get('/', [DataSettingController::class, 'index']);
    Route::middleware('permission.api:api.data_setting.building')->get('/building', [DataSettingController::class, 'building']);
    Route::middleware('permission.api:api.data_setting.addBuilding')->post('/building/add', [DataSettingController::class, 'addBuilding']);
    Route::middleware('permission.api:api.data_setting.floor')->get('/floor', [DataSettingController::class, 'floor']);
    Route::middleware('permission.api:api.data_setting.doorplate')->get('/doorplate', [DataSettingController::class, 'doorplate']);
    Route::middleware('permission.api:api.data_setting.addDoorplate')->post('/doorplate/create', [DataSettingController::class, 'addDoorplate']);
    Route::middleware('permission.api:api.data_setting.updateDoorplate')->post('/doorplate/update', [DataSettingController::class, 'updateDoorplate']);
    Route::middleware('permission.api:api.data_setting.deleteDoorplate')->post('/doorplate/delete', [DataSettingController::class, 'deleteDoorplate']);
});
