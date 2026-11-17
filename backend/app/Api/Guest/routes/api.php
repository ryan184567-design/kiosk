<?php

use Illuminate\Support\Facades\Route;
use App\Api\Guest\Controllers\GuestController;

// 見 README 6.9：模塊路由需掛載 module:{code} middleware。
Route::middleware('module:guest')->prefix('guest')->group(function () {
    Route::get('/', [GuestController::class, 'index']);
});
