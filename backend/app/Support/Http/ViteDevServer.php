<?php

/* *****************************************
File: ViteDevServer.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 4.2.1 第 2 點：判斷目前是否為 Vite dev server 模式、實際 origin 為何，不寫死 port（Vite 遇到 port 衝突會自動換一個）。
***************************************** */

namespace App\Support\Http;

// 見 README 4.2.1 第 2 點：判斷目前是否為 Vite dev server 模式、實際 origin
// 為何，不寫死 port（Vite 遇到 port 衝突會自動換一個）。刻意不透過 Vite
// Facade 實作、純檔案 I/O——因為 config/cors.php 需要在 config 載入階段
// （早於 Facade 註冊，見 Illuminate\Foundation\Bootstrap 開機順序）就呼叫
// 這個方法，用 Facade 會直接噴 "A facade root has not been set" 錯誤。
// SecurityHeaders（4.2.1）也共用同一份，不要各自重複實作一次判斷邏輯。
class ViteDevServer
{
    public static function currentOrigin(): ?string
    {
        // 刻意不透過 Vite Facade、純檔案 I/O——因為 config/cors.php 需要在 config
        // 載入階段就呼叫這個方法，用 Facade 會直接噴 "A facade root has not been set"。
        //
        // 讀 app.env 一律「config 優先、env() 備援」：這個方法也會在實際請求
        // 期間被 SecurityHeaders 呼叫，而 `php artisan config:cache` 之後
        // Laravel 根本不載入 .env，執行期的 env() 一律回傳 null——只讀 env()
        // 會讓判斷變成「非 local」，dev server 永遠不被採用（同類問題見
        // SiteDatabaseConfig 的說明，那一次是讓登入直接 500）。
        // config 載入期間 config('app.env') 已經拿得到（app.php 先於 cors.php），
        // 但仍保留 env() 備援，不依賴檔案載入順序。
        $appEnv = config('app.env') ?? env('APP_ENV');

        if ($appEnv !== 'local') {
            return null;
        }

        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $hotFileContent = trim(file_get_contents($hotFile));
        $parts = parse_url($hotFileContent);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        // CSP／CORS 的來源語法都不支援 IPv6 字面值（含中括號，如 [::1]），
        // 寧可當作「沒有 dev server」處理，也不要生出一個瀏覽器會忽略的
        // 假來源。根因已在 vite.config.js 固定 server.host=127.0.0.1
        // 解決，這裡只是最後一道防呆，見 4.2.1 相關說明。
        if (str_starts_with($parts['host'], '[')) {
            return null;
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }
}
