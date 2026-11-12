<?php

/* *****************************************
File: HqImpersonationCallbackController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-14
Description: 見 DEVELOP.MD 6.11 第 2-3 點：主站跳轉登入在子站端的 Blade 落地頁，
             兌換交換碼後把子站 JWT 交給管理後台 SPA 並導向該端口後台首頁
             （做法比照 8.6 SSO 落地頁）。
***************************************** */

namespace App\Web\Impersonation\Callback\Controllers;

use App\Support\Impersonation\HqImpersonationService;
use App\Support\Site\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

// 見 DEVELOP.MD 6.11 第 2-3 點：主站跳轉登入在子站端的 Blade 落地頁。
//
// 為什麼需要這一頁、不能只用 GET /api/hq-impersonate/exchange：主站是「導頁」
// 過來（瀏覽器網址列直接開），導頁帶不了 Authorization header，而且那支回傳
// JSON，瀏覽器只會看到一頁 JSON。這一頁負責把交換碼換成正式的子站 JWT 之後，
// 用兩種方式把登入狀態交付出去（見下方 callback 內的說明），最後 302/前端導向
// 該端口後台首頁，完成「按一下就進到子站後台」的體驗。
//
// 不掛 jwt.auth——這支本身就是登入入口，使用者這一刻還沒有子站 JWT；但要掛
// site.context（見 routes/web.php），才知道這次請求落在哪個子站，用來比對
// 交換碼綁定的站點是否相符。
class HqImpersonationCallbackController extends Controller
{
    public function callback(Request $request, SiteContext $siteContext, HqImpersonationService $impersonation)
    {
        $result = $impersonation->redeem($request->query('code'), $siteContext->siteId, $request->ip());

        if (! $result['ok']) {
            // 見 6.11 第 4 點：任何一步失敗都只顯示對應訊息，不洩漏內部細節。
            return response()->view('impersonation.error', ['reason' => $result['error']], 403);
        }

        $portalKey = $result['portalKey'];

        // 路徑前綴進站的子站（見 6.1）網址第一段就是站點代碼，導向後台時要
        // 一起帶上；網域已綁定的子站則不需要。判斷方式與 SpaFallbackController
        // 的「裸網域根目錄」判斷一致（segment(1) 等於目前站點代碼）。
        $prefix = ($siteContext->code !== '' && $request->segment(1) === $siteContext->code)
            ? "/{$siteContext->code}"
            : '';

        // 目前只有 admin 端口有對應的 SPA（見 README 1.2 第 4 點：portals
        // 目前只 seed 了 admin），其他端口先導向 /{portal_key}，等該端口自己的
        // SPA 建置好（見 9.1）就會在同一條路徑上接手。
        $target = $prefix.($portalKey === 'admin' ? '/admin' : "/{$portalKey}");

        // 管理後台是純 SPA，JWT 存在 localStorage（見
        // resources/vue_src_admin/src/stores/auth.js 的 STORAGE_KEY），
        // 光設 cookie 進不了後台；因此落地頁需要把 token 交給同一個
        // localStorage key，SPA 一載入就是已登入狀態。兩邊的 key 名稱必須
        // 一致，改動任一邊請同步另一邊。
        $storageKey = $portalKey === 'admin' ? 'admin_access_token' : null;

        // cookie 與 localStorage 兩者並存不是多餘：cookie 給「瀏覽器直接導頁
        // 的 Blade 頁面／同一來源的 API 呼叫」用（見 11.1、8.6：
        // EnsureJwtAuthenticated 在沒有 bearer token 時會 fallback 讀 cookie），
        // localStorage 給 SPA 用。屬性依 4.2.2 一律嚴格：HttpOnly（不可被 JS
        // 讀取）、SameSite=Lax、Secure 依當次請求是否為 HTTPS 動態決定（本機開發
        // 沒有反向代理時自然是 false，不算放寬）。
        return response()
            ->view('impersonation.landing', [
                'target' => $target,
                'storageKey' => $storageKey,
                'token' => $result['token'],
                'portalKey' => $portalKey,
            ])
            ->withCookie(Cookie::make(
                name: (string) config('jwt.cookie_name'),
                value: $result['token'],
                minutes: (int) config('jwt.ttl_minutes'),
                path: '/',
                secure: $request->secure(),
                httpOnly: true,
                sameSite: 'lax',
            ));
    }
}
