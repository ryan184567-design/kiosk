<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站跳轉登入子站，見 README 6.11。
***************************************** */

use App\Hq\Impersonation\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

// 主站跳轉登入子站，見 README 6.11。高風險操作一律要求 hq.super_admin。
Route::middleware(['hq.jwt.auth', 'hq.super_admin'])->group(function () {
    Route::post('/sites/{siteId}/impersonate-token', [ImpersonationController::class, 'issueToken']);
});
