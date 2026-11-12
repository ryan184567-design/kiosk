<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站登入，見 README 6.12。
***************************************** */

use App\Hq\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// 主站登入，見 README 6.12。
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('hq.jwt.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user/profile', [AuthController::class, 'profile']);
});
