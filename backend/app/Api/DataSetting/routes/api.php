<?php

use Illuminate\Support\Facades\Route;
use App\Api\DataSetting\Controllers\DataSettingController;
use App\Api\DataSetting\Controllers\FactoryDataSettingController;

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


// 廠商模式（App Token）：訪客機以 App 身份呼叫，路徑一律在 /api/factory/*（DEVELOP.MD 12.8）。
// 認證用 factory.auth、權限用 factory.scope，與上面的用戶 jwt.auth／permission.api 是兩套互不相干的認證；
// 新增接口後執行 `php artisan factory:sync-scopes` 登記 scope。
Route::prefix('factory/data_setting')->middleware(['module:data_setting', 'factory.auth'])->group(function () {
    Route::get('index', [FactoryDataSettingController::class, 'index'])->middleware('factory.scope:data_setting.doorplate.index');
});
