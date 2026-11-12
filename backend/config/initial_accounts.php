<?php

/* *****************************************
File: initial_accounts.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 唯一的「初始帳號怎麼設」定義處，見 6.3、6.12、18.1。
***************************************** */

// 唯一的「初始帳號怎麼設」定義處，見 6.3、6.12、18.1。
// 每次 site:create／主站首次建置都從這裡讀取帳密，不是每次隨機產生——
// 這些是我方內部帳號（超級管理員、hq 管理員），本來就該是固定、我方自己
// 知道的憑證，而不是每個站各自一組要另外去 console 輸出裡翻的密碼。
//
// 密碼故意不給預設值：沒在 .env 設定就直接讓 seeder 丟例外中斷（見 4.2
// 的「嚴格優先、及早失敗」原則），不用隨機產生的密碼糊弄過去。
//
// 帳號預設值原則（見 README 6.12）：hq_admin、site_super_admin 是我方
// 內部固定帳號，每個資料庫（主站／每個子站）都用同一個慣例帳號 admin，
// 好記、不用每個資料庫各自查一次；site_delegation_root 是交付客戶的帳號，
// 預設改用該子站自己的代號（例如 code=vendora 對應 username=vendora），
// 讓帳號本身就能識別是哪個站，而不是每個站都叫 admin、要另外對照資料庫
// 才知道是哪一個。這個值跟站點代碼綁定、只有 seed 當下才知道，所以這裡
// 留空、不能寫死預設值，實際計算見 database/seeders/SiteBaselineSeeder.php
// 的 currentSiteCode()。三者都保留 .env 覆寫的空間，設定了就一律以設定
// 值為準。

return [

    'hq_admin' => [
        'username' => env('HQ_ADMIN_USERNAME', 'admin'),
        'password' => env('HQ_ADMIN_PASSWORD'),
    ],

    'site_super_admin' => [
        'username' => env('SITE_SUPER_ADMIN_USERNAME', 'admin'),
        'password' => env('SITE_SUPER_ADMIN_PASSWORD'),
    ],

    'site_delegation_root' => [
        'username' => env('SITE_DELEGATION_ROOT_USERNAME'),
        'password' => env('SITE_DELEGATION_ROOT_PASSWORD'),
    ],

];
