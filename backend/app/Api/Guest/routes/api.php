<?php

use Illuminate\Support\Facades\Route;
use App\Api\Guest\Controllers\GuestApplyController;
use App\Api\Guest\Controllers\GuestSlotController;
use App\Api\Guest\Controllers\VisitorMachineController;


Route::middleware(['module:guest', 'jwt.auth'])->prefix('guest')->group(function () {

    Route::prefix('applies')->group(function () {
        Route::middleware('permission.api:api.guest.index')->get('/', [GuestApplyController::class, 'index']);
        Route::middleware('permission.api:api.guest.show')->get('/{id}', [GuestApplyController::class, 'show'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.store')->post('/', [GuestApplyController::class, 'store']);
        Route::middleware('permission.api:api.guest.update')->put('/{id}', [GuestApplyController::class, 'update'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.cancel')->put('/{id}/cancel', [GuestApplyController::class, 'cancel'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.statusChange')->put('/{id}/status', [GuestApplyController::class, 'statusChange'])->whereNumber('id');

        Route::put('/check', [VisitorMachineController::class, 'check']);
        Route::put('/checkDone', [VisitorMachineController::class, 'checkDone']);
    });

    Route::prefix('slots')->group(function () {
        Route::middleware('permission.api:api.guest.slotIndex')->get('/', [GuestSlotController::class, 'slotIndex']);
        Route::middleware('permission.api:api.guest.slotStore')->post('/', [GuestSlotController::class, 'slotStore']);
        Route::middleware('permission.api:api.guest.slotUpdate')->put('/{id}', [GuestSlotController::class, 'slotUpdate'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.slotDestroy')->delete('/{id}', [GuestSlotController::class, 'slotDestroy'])->whereNumber('id');
    });
});
