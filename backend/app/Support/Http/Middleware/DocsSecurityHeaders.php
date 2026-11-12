<?php

/* *****************************************
File: DocsSecurityHeaders.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 4.2.1 第 6 點：Swagger UI（/docs、/docs/partner）需要的 CSP 放寬 僅能限縮在這兩條路由本身，獨立一份 middleware、不進全域的 SecurityHeaders， 避免整站 CSP 因此變寬鬆。
***************************************** */

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

// 見 README 4.2.1 第 6 點：Swagger UI（/docs、/docs/partner）需要的 CSP 放寬
// 僅能限縮在這兩條路由本身，獨立一份 middleware、不進全域的 SecurityHeaders，
// 避免整站 CSP 因此變寬鬆。路由層以 ->withoutMiddleware(SecurityHeaders::class)
// 移除全域套用，改掛這支。
//
// 已實測（Playwright 載入 /docs 檢查主控台）：swagger-ui-bundle.js 執行期會
// 固定注入一段 <style> 標籤（同一份 CSS，內容不隨請求變動），不是動態拼出來
// 的內容，也沒有用到 inline style="..." 屬性、沒有 unsafe-eval 需求。比起
// 4.2.1 第 5 點講的 style-src-attr unsafe-inline 折衷做法，這裡改用瀏覽器
// 違規訊息直接給的 CSP hash source（sha256-...）——同一份固定內容用 hash
// 允許比 unsafe-inline 更嚴格（只允許這個 bundle 版本注入的這一份 CSS，換
// 版本內容變了 hash 就會跟著失效、需要重新計算，不會被其他注入的內容冒用），
// script-src 全程沒有違規，維持嚴格（self+nonce），未放寬。
class DocsSecurityHeaders
{
    private const SWAGGER_UI_STYLE_HASH = "'sha256-RL3ie0nH+Lzz2YNqQN83mnU0J1ot4QL7b99vMdIX99w='";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $nonce = Vite::cspNonce();

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            'style-src-elem \'self\' \'nonce-'.$nonce.'\' '.self::SWAGGER_UI_STYLE_HASH,
            "connect-src 'self'",
            "img-src 'self' data:",
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
