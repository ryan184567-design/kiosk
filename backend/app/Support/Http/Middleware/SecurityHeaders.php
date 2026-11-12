<?php

/* *****************************************
File: SecurityHeaders.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 4.2.1：嚴格 CSP，不使用 unsafe-inline/unsafe-eval。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Support\Http\ViteDevServer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

// 見 README 4.2.1：嚴格 CSP，不使用 unsafe-inline/unsafe-eval。
// Vite dev server（npm run dev）依 hot 檔案是否存在自動放寬，正式環境
// 沒有 hot 檔案，自動維持嚴格——不是靠工程師手動切換。
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Vite::useCspNonce() 已在 AppServiceProvider::boot() 呼叫過一次，
        // 這裡只需要取用同一個值，見 4.2.1 第 3 點。
        $nonce = Vite::cspNonce();

        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $styleSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];

        // 見 ViteDevServer 開頭說明：判斷邏輯與 config/cors.php 共用同一份，
        // 不要各自重複實作一次。僅本機 npm run dev 期間會有值，見 4.2.1
        // 第 2 點；正式環境沒有 hot 檔案，這個分支永遠不會執行。
        if ($devServer = ViteDevServer::currentOrigin()) {
            // 來源直接讀 hot 檔案內容，不寫死 port——Vite 遇到 port 衝突時
            // 會自動換一個（例如 5173 被佔用時跳到 5174），寫死會導致換了
            // port 之後 CSP 放行的來源跟實際 dev server 對不上，反而擋住開發。
            $devServerWs = preg_replace('#^http#', 'ws', $devServer);
            $scriptSrc[] = $devServer;
            $scriptSrc[] = "'unsafe-eval'";
            $styleSrc[] = $devServer;
            $connectSrc[] = $devServer;
            $connectSrc[] = $devServerWs;
        }

        $csp = implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            'style-src '.implode(' ', $styleSrc),
            'connect-src '.implode(' ', $connectSrc),
            // blob: 是「編輯圖片」對話框載入原圖預覽用（見 README 17.5、
            // ImageCropDialog.vue）：原圖存在私有磁碟不能直接當 <img src>，
            // 前端改用 authFetch 讀成 blob 後 URL.createObjectURL()，該網址
            // scheme 就是 blob:，不放行會被 CSP 擋下（已實測踩過）。
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
