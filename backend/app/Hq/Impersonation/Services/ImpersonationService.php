<?php

/* *****************************************
File: ImpersonationService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-14
Description: 主站端核發跳轉登入交換碼，並組出子站的落地頁網址
             （見 DEVELOP.MD 6.11 第 1-2 點）。
***************************************** */

namespace App\Hq\Impersonation\Services;

use App\Hq\Site\Models\Site;
use App\Support\Audit\Models\HqAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// 見 DEVELOP.MD 6.11 第 1-2 點：主站端核發跳轉登入交換碼。
//
// 分兩段的理由（交換碼 vs 直接給子站 JWT）見 6.11 開頭：交換碼要跟著網址
// 參數明碼傳輸、經過瀏覽器導頁，直接放真正的 JWT 風險較高；交換碼只在極短
// 時間窗口（60 秒）內有效且一次即失效，即使外洩也難以被利用。
//
// 業務邏輯刻意不寫在 Controller：Controller 只做入參驗證與回應（4 節第 3 點），
// 而且「落地頁網址怎麼組」屬於站點拓樸知識（網域／路徑前綴，見 6.1），
// 放在這裡日後新增進站方式只需要改一處。
class ImpersonationService
{
    // 見 6.11 第 1 點：交換碼 TTL 60 秒。子站端兌換後立即刪除（
    // App\Support\Impersonation\HqImpersonationService::redeem），
    // 兩邊都靠這個值對齊，改動請一起改。
    public const CODE_TTL_SECONDS = 60;

    // 子站端的落地頁路徑，見 6.11 第 2 點與
    // app/Web/Impersonation/Callback/routes/web.php。抽成常數是因為「主站組
    // 網址」與「子站註冊路由」是兩份程式碼，寫死字串一旦有一邊改動，錯的
    // 情況是「按了跳轉卻 404」，不會有任何提示。
    public const LANDING_PATH = '/hq-impersonate/callback';

    /**
     * @return array{code: string, expires_in: int, site_code: string, redirect_url: string}
     */
    public function issue(Request $request, Site $site, string $portalKey, ?int $hqUserId): array
    {
        $code = (string) Str::uuid();

        // 見 6.11 第 1 點：共用（非站點專屬）cache，主站與子站都讀得到同一份。
        // 用 Laravel 預設 cache（見 config/cache.php：database 連線為預設連線
        // ＝主站，不受任何子站的 site_current 連線影響），兩邊才對得上。
        Cache::put("hq:impersonation:{$code}", [
            'site_id' => $site->site_id,
            'portal_key' => $portalKey,
            'requested_by' => $hqUserId,
            'issued_at' => now()->toIso8601String(),
        ], now()->addSeconds(self::CODE_TTL_SECONDS));

        // 見 15 節第 5 點：主站層級操作寫入主站自己的 audit_logs（子站端兌換時
        // 會另外寫一筆子站的，兩邊各自記錄、不跨庫 join）。
        HqAuditLog::query()->create([
            'user_id' => $hqUserId,
            'action' => 'impersonation.issue',
            'target' => "site:{$site->site_id}",
            'context' => ['portal_key' => $portalKey],
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return [
            'code' => $code,
            'expires_in' => self::CODE_TTL_SECONDS,
            'site_code' => $site->code,
            'redirect_url' => $this->landingUrl($request, $site, $code),
        ];
    }

    // 見 6.11 第 2 點：回傳「瀏覽器要導向的子站落地頁完整網址」，讓主站前端
    // 不需要自己拼網址（前端不知道子站的網域綁定狀態與路徑前綴規則）。
    private function landingUrl(Request $request, Site $site, string $code): string
    {
        // 兩種進站方式（見 6.1）：
        //   - 已綁定主要網域：直接用該網域，子站可能有自己的憑證／網域。
        //   - 只有路徑前綴：用「主站目前這個網域 + /{code}」——主站與子站本來
        //     就是同一個 Laravel 應用（見 6.4），只是靠路徑前綴區分，所以
        //     主站前端看到的主機名稱就是子站要用的主機名稱。scheme 走
        //     $request->getScheme()（受 TrustProxies 影響，見 4.2.2），
        //     反向代理後面才不會組出 http:// 的網址被 CSP／瀏覽器降級。
        $base = $site->primary_domain
            ? $request->getScheme().'://'.$site->primary_domain
            : $request->getSchemeAndHttpHost().'/'.$site->code;

        return $base.self::LANDING_PATH.'?code='.urlencode($code);
    }
}
