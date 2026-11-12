<?php

/* *****************************************
File: SsoCallbackController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 8.6：SSO 導頁落地頁，Blade 渲染（3.2 第 1 點「對接 SSO 的跳轉頁面 會使用 Blade 渲染」）。
***************************************** */

namespace App\Web\Sso\Callback\Controllers;

use App\Support\Sso\SsoSiteConfig;
use App\Web\Sso\Callback\Services\SsoLoginService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

// 見 README 8.6：SSO 導頁落地頁，Blade 渲染（3.2 第 1 點「對接 SSO 的跳轉頁面
// 會使用 Blade 渲染」）。不掛 jwt.auth——這支本身就是登入入口，使用者這時
// 還沒有內部 JWT。
class SsoCallbackController extends Controller
{
    public function callback(Request $request, SsoLoginService $service, SsoSiteConfig $siteConfig)
    {
        $result = $service->handle($request);

        if (! $result['ok']) {
            return response()->view('sso.error', ['reason' => $result['error']], 403);
        }

        $target = $this->resolveRedirectTarget($request, $siteConfig);

        // 見 4.2.2：HttpOnly／SameSite 與 TLS 無關，一律嚴格設定；Secure 依
        // 實際請求是否為 HTTPS 動態決定（本機開發沒有反向代理時自然是 false，
        // 不算放寬，見同節說明）。cookie 存的是內部 JWT（issueSession 核發），
        // 不是外部 SSO 系統的憑證。
        return redirect($target)->cookie(Cookie::make(
            name: (string) config('jwt.cookie_name'),
            value: $result['token'],
            minutes: (int) config('jwt.ttl_minutes'),
            path: '/',
            secure: $request->secure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    // 只允許站內相對路徑，避免 ?redirect= 被拿來當 open redirect 跳板
    // （例如 https://legit-site/sso/callback?redirect=https://evil.example）。
    private function resolveRedirectTarget(Request $request, SsoSiteConfig $siteConfig): string
    {
        $default = $siteConfig->load()['sso_success_redirect'] ?? '/';
        $requested = $request->query('redirect');

        if (! is_string($requested) || $requested === '' || ! str_starts_with($requested, '/') || str_starts_with($requested, '//')) {
            return $default ?: '/';
        }

        return $requested;
    }
}
