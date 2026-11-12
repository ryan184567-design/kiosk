<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站模塊開通/下架，見 README 6.9 第 2 點。
***************************************** */

use App\Hq\SiteModule\Controllers\SiteModuleController;
use Illuminate\Support\Facades\Route;

// 子站模塊開通/下架，見 README 6.9 第 2 點。高風險操作一律要求 hq.super_admin。
Route::middleware(['hq.jwt.auth', 'hq.super_admin'])->group(function () {
    Route::get('/sites/{siteId}/modules', [SiteModuleController::class, 'index']);
    Route::post('/sites/{siteId}/modules/{moduleCode}/enable', [SiteModuleController::class, 'enable']);
    Route::post('/sites/{siteId}/modules/{moduleCode}/disable', [SiteModuleController::class, 'disable']);
});
