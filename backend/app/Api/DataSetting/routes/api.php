<?php

use Illuminate\Support\Facades\Route;
use App\Api\DataSetting\Controllers\DataSettingController;

// 見 README 6.9：模塊路由需掛載 module:{code} middleware。
Route::middleware('module:data_setting')->prefix('data_setting')->group(function () {
    Route::get('/', [DataSettingController::class, 'index']);
    Route::get('/building', [DataSettingController::class, 'building']);
    Route::post('/building/add', [DataSettingController::class, 'addBuilding']);
    Route::get('/floor', [DataSettingController::class, 'floor']);
    Route::get('/doorplate', [DataSettingController::class, 'doorplate']);
    Route::post('/doorplate/create', [DataSettingController::class, 'addDoorplate']);
    Route::post('/doorplate/update', [DataSettingController::class, 'updateDoorplate']);
    Route::post('/doorplate/delete', [DataSettingController::class, 'deleteDoorplate']);
});
