<?php

use App\Api\Factory\Controllers\AppController;
use App\Api\Factory\Controllers\FactoryAuthController;
use App\Api\Factory\Controllers\AppLogController;
use Illuminate\Support\Facades\Route;

// 廠商模式（App Token）：機器／第三方以 App 身份呼叫 /api/factory/*，見 DEVELOP.MD 12.8、12.9。
// 核心模塊，不掛 module:{code}（is_core 恆為開通，見 6.9）。

// 換取 app token：本身就是認證入口，不掛 factory.auth，但要限流
Route::post('factory/token', [FactoryAuthController::class, 'token'])->middleware('throttle:factory-token');

// 後台管理 App／授權／記錄：後台用戶呼叫（jwt.auth + permission.api），與 App Token 是兩套互不相干的認證
Route::middleware('jwt.auth')->prefix('system/factory')->group(function () {
    Route::prefix('apps')->group(function () {
        Route::middleware('permission.api:api.factory.appIndex')->get('/', [AppController::class, 'appIndex']);
        Route::middleware('permission.api:api.factory.appStore')->post('/', [AppController::class, 'appStore']);
        Route::middleware('permission.api:api.factory.appShow')->get('/{id}', [AppController::class, 'appShow'])->whereNumber('id');
        Route::middleware('permission.api:api.factory.appUpdate')->put('/{id}', [AppController::class, 'appUpdate'])->whereNumber('id');
        Route::middleware('permission.api:api.factory.appDestroy')->delete('/{id}', [AppController::class, 'appDestroy'])->whereNumber('id');
        Route::middleware('permission.api:api.factory.appResetKey')->post('/{id}/reset-secret', [AppController::class, 'appResetKey'])->whereNumber('id');
        Route::middleware('permission.api:api.factory.appSetScopes')->put('/{id}/scopes', [AppController::class, 'appSetScopes'])->whereNumber('id');
    });

    Route::middleware('permission.api:api.factory.appScopes')->get('scopes', [AppController::class, 'appScopes']);
    Route::middleware('permission.api:api.factory.logIndex')->get('logs', [AppLogController::class, 'logIndex']);
    Route::middleware('permission.api:api.factory.logShow')->get('logs/{id}', [AppLogController::class, 'logShow'])->whereNumber('id');
});
