<?php

/* *****************************************
File: api.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 核心功能，不掛 module:{code}，見 README 8.2：前台 SPA 啟動就要呼叫， 不應該因為模塊未開通而被擋。
***************************************** */

use App\Api\Site\Controllers\SiteConfigController;
use Illuminate\Support\Facades\Route;

// 核心功能，不掛 module:{code}，見 README 8.2：前台 SPA 啟動就要呼叫，
// 不應該因為模塊未開通而被擋。
Route::get('/site/config', [SiteConfigController::class, 'show']);
