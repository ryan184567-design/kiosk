<?php

/* *****************************************
File: HqSpaController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站後台 Vue3 SPA 入口頁，見 README 6.12。
***************************************** */

namespace App\Support\Http\Controllers;

use Illuminate\Http\Response;

// 主站沒有多站/多租戶概念，不像 SpaFallbackController 要依 SiteContext
// 判斷回傳哪個站點的編譯產物，固定回傳同一份 public/hq-assets/index.html
// 即可；瀏覽器內的 Vue Router（history 模式）接手處理 /hq/* 底下的子路徑。
class HqSpaController
{
    public function __invoke(): Response
    {
        $indexPath = public_path('hq-assets/index.html');

        if (! file_exists($indexPath)) {
            abort(404, '主站後台尚未建置，請先執行 npm run hq:build。');
        }

        return response(file_get_contents($indexPath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
