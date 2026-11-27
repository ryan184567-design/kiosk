<?php

use Illuminate\Support\Facades\Route;
use App\Api\Access\Controllers\AccessController;
use App\Api\Access\Controllers\AccessFloorSettingController;
use App\Api\Access\Controllers\AccessApiController;
use App\Api\Access\Controllers\AccessCardController;

// 見 README 6.9：模塊路由需掛載 module:{code} middleware。
Route::middleware(['module:access', 'jwt.auth'])->prefix('access')->group(function () {
    Route::prefix('floor')->group(function () {
        Route::middleware('permission.api:api.floor.indexFloorAccess')->get('/', [AccessFloorSettingController::class, 'indexFloorAccess']);
        Route::middleware('permission.api:api.floor.showFloorAccess')->get('/{id}', [AccessFloorSettingController::class, 'showFloorAccess'])->whereNumber('id');
        Route::middleware('permission.api:api.floor.storeFloorAccess')->post('/', [AccessFloorSettingController::class, 'storeFloorAccess']);
        Route::middleware('permission.api:api.floor.updateFloorAccess')->put('/{id}', [AccessFloorSettingController::class, 'updateFloorAccess'])->whereNumber('id');
        Route::middleware('permission.api:api.floor.destroyFloorAccess')->delete('/{id}', [AccessFloorSettingController::class, 'destroyFloorAccess'])->whereNumber('id');
    });

    Route::prefix('cards')->group(function () {
        Route::middleware('permission.api:api.card.indexCard')->get('/', [AccessCardController::class, 'indexCard']);
        Route::middleware('permission.api:api.card.storeCard')->post('/', [AccessCardController::class, 'storeCard']);
        Route::middleware('permission.api:api.card.updateCard')->put('/{id}', [AccessCardController::class, 'updateCard'])->whereNumber('id');
        Route::middleware('permission.api:api.card.destroyCard')->delete('/{id}', [AccessCardController::class, 'destroyCard'])->whereNumber('id');
    });

    Route::prefix('api')->group(function () {
        Route::middleware('permission.api:api.api.indexApi')->get('/', [AccessApiController::class, 'indexApi']);
        Route::middleware('permission.api:api.api.storeApi')->post('/', [AccessApiController::class, 'storeApi']);
        Route::middleware('permission.api:api.api.updateApi')->put('/{id}', [AccessApiController::class, 'updateApi'])->whereNumber('id');
        Route::middleware('permission.api:api.api.destroyApi')->delete('/{id}', [AccessApiController::class, 'destroyApi'])->whereNumber('id');
    });
});
