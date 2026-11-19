<?php

use Illuminate\Support\Facades\Route;
use App\Api\Guest\Controllers\GuestApplyController;
use App\Api\Guest\Controllers\GuestSlotController;


Route::middleware(['module:guest', 'jwt.auth'])->prefix('guest')->group(function () {

    Route::prefix('applies')->group(function () {
        Route::middleware('permission.api:api.guest_apply.index')->get('/', [GuestApplyController::class, 'index']);
        Route::middleware('permission.api:api.guest_apply.show')->get('/{id}', [GuestApplyController::class, 'show'])->whereNumber('id');
        Route::middleware('permission.api:api.guest_apply.store')->post('/', [GuestApplyController::class, 'store']);
        Route::middleware('permission.api:api.guest_apply.update')->put('/{id}', [GuestApplyController::class, 'update'])->whereNumber('id');
        Route::middleware('permission.api:api.guest_apply.cancel')->put('/{id}/cancel', [GuestApplyController::class, 'cancel'])->whereNumber('id');
        Route::middleware('permission.api:api.guest_apply.statusChange')->put('/{id}/status', [GuestApplyController::class, 'statusChange'])->whereNumber('id');
    });

    Route::prefix('slots')->group(function () {
        Route::middleware('permission.api:api.guest_slot.index')->get('/', [GuestSlotController::class, 'index']);
        Route::middleware('permission.api:api.guest_apply.store')->post('/', [GuestSlotController::class, 'store']);
        Route::middleware('permission.api:api.guest_apply.update')->put('/{id}', [GuestSlotController::class, 'update'])->whereNumber('id');
        Route::middleware('permission.api:api.guest_apply.destroy')->delete('/{id}', [GuestSlotController::class, 'destroy'])->whereNumber('id');
    });
});
