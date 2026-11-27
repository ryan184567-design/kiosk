<?php

/* *****************************************
File: CompanySpaController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口（company）Vue3 SPA 入口頁；比照 AdminSpaController，固定回傳同一份編譯產物。
***************************************** */

namespace App\Support\Http\Controllers;

use Illuminate\Http\Response;

// 公司端口給訪客模組的各公司帳號登入（見 App\Api\Guest\Middleware\EnsureGuestCompanyScope），
// 跟管理後台一樣業務邏輯不因子站而異；掛 site.context 讓 SiteResolver 在網域／路徑前綴
// 兩種進站方式下都能判斷出子站（見 routes/web.php）。
class CompanySpaController
{
    public function __invoke(): Response
    {
        $indexPath = public_path('company-assets/index.html');

        if (! file_exists($indexPath)) {
            abort(404, '公司端口尚未建置，請先執行 npm run company:build。');
        }

        return response(file_get_contents($indexPath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
