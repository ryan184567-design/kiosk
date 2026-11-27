<?php

use Illuminate\Support\Facades\Route;
use App\Api\Access\Controllers\AccessController;
use App\Api\Access\Controllers\AccessFloorSettingController;
use App\Api\Access\Controllers\AccessApiController;
use App\Api\Access\Controllers\AccessCardController;

// 見 README 6.9：模塊路由需掛載 module:{code} middleware。
Route::middleware(['module:access', 'jwt.auth'])->prefix('access')->group(function () {
    Route::prefix('floor')->group(function () {
        Route::middleware('permission.api:api.access.indexFloorAccess')->get('/', [AccessFloorSettingController::class, 'indexFloorAccess']);
        Route::middleware('permission.api:api.access.showFloorAccess')->get('/{id}', [AccessFloorSettingController::class, 'showFloorAccess'])->whereNumber('id');
        Route::middleware('permission.api:api.access.storeFloorAccess')->post('/', [AccessFloorSettingController::class, 'storeFloorAccess']);
        Route::middleware('permission.api:api.access.updateFloorAccess')->put('/{id}', [AccessFloorSettingController::class, 'updateFloorAccess'])->whereNumber('id');
        Route::middleware('permission.api:api.access.destroyFloorAccess')->delete('/{id}', [AccessFloorSettingController::class, 'destroyFloorAccess'])->whereNumber('id');
    });

    Route::prefix('cards')->group(function () {
        Route::middleware('permission.api:api.access.indexCard')->get('/', [AccessCardController::class, 'indexCard']);
        Route::middleware('permission.api:api.access.storeCard')->post('/', [AccessCardController::class, 'storeCard']);
        Route::middleware('permission.api:api.access.updateCard')->put('/{id}', [AccessCardController::class, 'updateCard'])->whereNumber('id');
        Route::middleware('permission.api:api.access.destroyCard')->delete('/{id}', [AccessCardController::class, 'destroyCard'])->whereNumber('id');
    });

    Route::prefix('api')->group(function () {
        Route::middleware('permission.api:api.access.indexApi')->get('/', [AccessApiController::class, 'indexApi']);
        Route::middleware('permission.api:api.access.storeApi')->post('/', [AccessApiController::class, 'storeApi']);
        Route::middleware('permission.api:api.access.updateApi')->put('/{id}', [AccessApiController::class, 'updateApi'])->whereNumber('id');
        Route::middleware('permission.api:api.access.destroyApi')->delete('/{id}', [AccessApiController::class, 'destroyApi'])->whereNumber('id');
    });
});
