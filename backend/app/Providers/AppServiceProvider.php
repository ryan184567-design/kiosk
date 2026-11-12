<?php

/* *****************************************
File: AppServiceProvider.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 應用程式啟動註冊：Vite CSP nonce（見 README 4.2.1 第 3 點）、建站流程限流規則（見 6.3）。
***************************************** */

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 見 README 4.2.1 第 3 點：讓 @vite 產出的 <script>/<link> 自動帶上
        // 本次請求的 nonce，SecurityHeaders middleware 用 Vite::cspNonce() 取
        // 同一個值放進 CSP header。
        Vite::useCspNonce();

        // 見 README 6.3「資源耗盡與失敗補償」第 1 點：避免短時間內建立大量資料庫。
        RateLimiter::for('site-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // 見 README 6.8：單站部署包輸出也吃磁碟 I/O 與空間（整包複製共用程式碼），
        // 用比開站更嚴的限流，避免後台被連續點擊或迴圈呼叫灌爆。
        RateLimiter::for('site-package', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
    }
}
