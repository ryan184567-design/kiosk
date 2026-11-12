<?php

/* *****************************************
File: ApiDocsController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Swagger UI 頁面，見 README 12.1、12.7、12.8。
***************************************** */

namespace App\Support\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

// Swagger UI 頁面，見 README 12.1、12.7、12.8。跟 SpaFallbackController 一樣
// 屬於跨端口的基礎設施頁面，不歸在任何 app/Web/{portal}/{module} 底下。
// 靜態資源由 swagger:generate 一併從 node_modules/swagger-ui-dist 複製到
// public/vendor/swagger-ui（見 SwaggerGenerateCommand::publishSwaggerUiAssets），
// 不吃 CDN。openapi.json／openapi.partner.json 也透過這支讀檔回傳（見
// SwaggerGenerateCommand::write 開頭說明：故意不放在 public/docs，避免
// 跟 /docs 路由本身撞路徑，在 php artisan serve 下會直接被內建伺服器攔截）。
class ApiDocsController extends Controller
{
    public function internal()
    {
        return view('docs.index', ['title' => 'WebBase API', 'specUrl' => '/docs/openapi.json']);
    }

    public function partner()
    {
        return view('docs.index', ['title' => 'WebBase API（廠商）', 'specUrl' => '/docs/openapi.partner.json']);
    }

    public function hq()
    {
        return view('docs.index', ['title' => 'WebBase 主站 API', 'specUrl' => '/docs/openapi.hq.json']);
    }

    public function internalSpec()
    {
        return $this->readSpec(storage_path('app/private/docs/openapi.json'));
    }

    public function partnerSpec()
    {
        return $this->readSpec(storage_path('app/private/docs/openapi.partner.json'));
    }

    public function hqSpec()
    {
        return $this->readSpec(storage_path('app/private/docs/openapi.hq.json'));
    }

    private function readSpec(string $path): Response
    {
        if (! is_file($path)) {
            abort(404, '尚未產生 OpenAPI JSON，請先執行 php artisan swagger:generate。');
        }

        return response(file_get_contents($path), 200, ['Content-Type' => 'application/json']);
    }
}
