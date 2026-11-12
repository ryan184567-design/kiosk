<?php

/* *****************************************
File: web.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 8.6。
***************************************** */

use App\Web\Sso\Callback\Controllers\SsoCallbackController;
use Illuminate\Support\Facades\Route;

// 見 README 8.6。不綁定特定端口資料夾——目標端口由該站 system_configs 的
// sso_portal_key 決定，不是靠路由路徑判斷，所以沒有放在 app/Web/{portal}
// 底下，而是獨立的 Sso 功能模組（仍維持 app/Web/{module}/routes 兩層慣例，
// 讓 routes/web.php 的 glob 規則能掃到，見該檔案開頭說明）。
Route::middleware('site.context')
    ->get('/sso/callback', [SsoCallbackController::class, 'callback'])
    ->name('sso.callback');
