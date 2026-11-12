<?php

/* *****************************************
File: SpaFallbackController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 3.2 第 3 點：除了實際存在的系統路由（/api、/docs、/hq…）外， 其餘前台 GET 路徑都回傳對應的 Vue 入口頁，交由前端路由（Vue Router）處理；裸網域根目錄導向管理後台。
***************************************** */

namespace App\Support\Http\Controllers;

use App\Support\Site\SiteContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 見 README 3.2 第 3 點：除了實際存在的系統路由（/api、/docs、/hq…）外，
// 其餘前台 GET 路徑都回傳對應的 Vue 入口頁，交由前端路由（Vue Router）處理。
//
// 目前前台只是骨架頁面、沒有真正的業務內容，直接進到一片空白的首頁對使用者
// 沒有意義——裸網域根目錄（`/` 本身，或路徑前綴子站的 `/{code}` 本身、後面
// 完全沒有其他路徑片段）一律導向管理後台（`/admin` 或 `/{code}/admin`）。
// 日後前台補上真正的業務頁面後，拿掉這段判斷即可還原成單純的 SPA index.html
// 出口。
class SpaFallbackController
{
    public function __invoke(Request $request, SiteContext $siteContext): Response
    {
        $path = $request->path();

        if ($path === '/') {
            return redirect('/admin');
        }

        if ($siteContext->code !== '' && $path === $siteContext->code) {
            return redirect("/{$siteContext->code}/admin");
        }

        // 見 8.1：vue_build_mode 決定回傳共用 public/vue 還是該站獨立建置的
        // public/vue-sites/{code}。
        $indexPath = $siteContext->vueBuildMode === 'dedicated'
            ? public_path("vue-sites/{$siteContext->templateNamespace}/index.html")
            : public_path('vue/index.html');

        if (! file_exists($indexPath)) {
            abort(404, '前台尚未建置，請先執行 php artisan vue:build（見 18.2）。');
        }

        return response(file_get_contents($indexPath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
