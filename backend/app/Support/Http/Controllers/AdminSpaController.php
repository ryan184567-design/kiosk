<?php

/* *****************************************
File: AdminSpaController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站管理後台 Vue3 SPA 入口頁，見 README 7.2。
***************************************** */

namespace App\Support\Http\Controllers;

use Illuminate\Http\Response;

// 管理後台的業務邏輯不因子站而異，固定回傳同一份編譯產物，跟
// SpaFallbackController（前台，依 vue_build_mode 分公版/獨立建置）不同，
// 這裡不需要依站點判斷回傳哪一份。掛 site.context 的原因單純是要讓
// SiteResolver 在網域/路徑前綴兩種進站方式下都能正確判斷出目前是哪個
// 子站（見 routes/web.php 的路由註冊：同時支援 /admin 與
// /{sitePrefix}/admin 兩種網址形態），瀏覽器內的 Vue Router 接手處理
// /admin/* 底下的子路徑。
class AdminSpaController
{
    public function __invoke(): Response
    {
        $indexPath = public_path('admin-assets/index.html');

        if (! file_exists($indexPath)) {
            abort(404, '管理後台尚未建置，請先執行 npm run admin:build。');
        }

        return response(file_get_contents($indexPath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
