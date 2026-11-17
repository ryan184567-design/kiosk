<?php

use Illuminate\Support\Facades\Route;
use App\Api\Guest\Controllers\GuestApplyController;


Route::middleware(['module:guest', 'jwt.auth'])->prefix('guest/applies')->group(function () {
    Route::middleware('permission.api:api.guest_apply.index')->get('/', [GuestApplyController::class, 'index']);
    Route::middleware('permission.api:api.guest_apply.show')->get('/{id}', [GuestApplyController::class, 'show'])->whereNumber('id');
    Route::middleware('permission.api:api.guest_apply.store')->post('/', [GuestApplyController::class, 'store']);
    Route::middleware('permission.api:api.guest_apply.update')->put('/{id}', [GuestApplyController::class, 'update'])->whereNumber('id');
    Route::middleware('permission.api:api.guest_apply.cancel')->put('/{id}/cancel', [GuestApplyController::class, 'cancel'])->whereNumber('id');
});
