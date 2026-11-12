<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 核心模塊，不掛 module:{code}（is_core 恆為開通，見 6.9），登入本身也不能 卡在 jwt.auth 之後。
***************************************** */

use App\Api\Auth\Controllers\AuthController;
use App\Api\Auth\Controllers\HqImpersonationController;
use Illuminate\Support\Facades\Route;

// 核心模塊，不掛 module:{code}（is_core 恆為開通，見 6.9），登入本身也不能
// 卡在 jwt.auth 之後。
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('jwt.auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('jwt.auth')->group(function () {
    Route::get('/user/profile', [AuthController::class, 'profile']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'changePassword']);
});

// 見 README 6.11 第 2 點：子站端兌換交換碼，本身就是登入機制，不掛 jwt.auth。
Route::get('/hq-impersonate/exchange', [HqImpersonationController::class, 'exchange']);
