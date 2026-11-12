<?php

/* *****************************************
File: HqImpersonationService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-14
Description: 子站端兌換主站核發的跳轉登入交換碼（見 DEVELOP.MD 6.11 第 3 點），
             由 API（GET /api/hq-impersonate/exchange）與 Blade 落地頁
             （GET /hq-impersonate/callback）共用同一份邏輯。
***************************************** */

namespace App\Support\Impersonation;

use App\Api\Auth\Services\AuthService;
use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Portal\Models\Portal;
use Illuminate\Support\Facades\Cache;

// 見 DEVELOP.MD 6.11 第 3 點：主站管理員在主站後台核發一組一次性交換碼，
// 瀏覽器帶著這組碼導頁到目標子站，由這裡驗證並換成真正能用的子站 JWT。
//
// 為什麼放在 app/Support：這是「子站端兌換」這件事本身，兩個入口都要用同一份
// 實作——一個是給程式化呼叫的 JSON API（app/Api/Auth 的
// HqImpersonationController），一個是給瀏覽器導頁用的 Blade 落地頁
// （app/Web/Impersonation/Callback）。兩邊各寫一份最容易出現的情況是
// 「驗證規則悄悄分岔」（例如其中一邊忘了把交換碼刪掉、變成可以重放），
// 所以共用層是刻意的，不是為了少寫幾行。
class HqImpersonationService
{
    // 見 6.11 第 1 點：交換碼有效秒數由主站核發時寫入 cache TTL，
    // 這裡只是回傳給呼叫端顯示／文件用，兩邊必須一致。
    public const CODE_TTL_SECONDS = 60;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * 兌換交換碼並核發子站登入 session。
     *
     * 回傳的 error 值是「語言包 key 的尾段」，直接對應 lang/zh_TW/api.php 的
     * api.auth.*（見開發約定第 11 點：不寫死文字），呼叫端以
     * __("api.auth.{$error}") 取訊息即可，不需要各自再維護一份錯誤碼對照表。
     *
     * @param  mixed  $code  查詢字串原樣帶進來的值；刻意不收斂成 string 型別宣告，
     *                       見下方說明（`?code[]=` 這種陣列形態要能被擋成無效碼，
     *                       而不是撞上型別宣告變成 500）。
     * @return array{ok: true, token: string, expiresAt: int, portalKey: string, userId: int}|array{ok: false, error: string}
     */
    public function redeem(mixed $code, int $siteId, ?string $ip = null): array
    {
        // `$request->query()` 對 `?code[]=x` 這種形態會回傳陣列，直接餵進
        // 字串參數會是 TypeError（500）。交換碼本來就只能是字串，非字串一律
        // 視同沒帶碼處理，維持「失敗也要回一致的錯誤格式」的對外行為。
        $code = is_string($code) ? $code : null;

        if (! $code) {
            return ['ok' => false, 'error' => 'exchange_code_required'];
        }

        $cacheKey = "hq:impersonation:{$code}";
        $payload = Cache::get($cacheKey);

        // 見 6.11 第 3 點：驗證後立即刪除，防止重放——同一組碼不能換第二次。
        // 刻意在「檢查 payload 內容」之前就刪除：即使這次兌換因為站點不符等
        // 原因失敗，這組碼也一併失效，不讓攻擊者用同一組碼反覆試不同站點。
        Cache::forget($cacheKey);

        if (! is_array($payload)) {
            return ['ok' => false, 'error' => 'exchange_code_invalid'];
        }

        // 見 6.11 第 3 點：交換碼核發時已綁定目標站點，拿著 A 站的碼到 B 站
        // 兌換一律拒絕。
        if ((int) ($payload['site_id'] ?? 0) !== $siteId) {
            return ['ok' => false, 'error' => 'exchange_site_mismatch'];
        }

        // 換出來的身份固定是「目前子站的超級管理員」（identity_types.is_system
        // =true，每個子站 baseline 建站時一定會建立這個帳號，見 6.3）——主站
        // 管理員在子站資料庫裡根本沒有對應帳號，兩邊使用者體系完全獨立。
        $superAdmin = User::query()
            ->whereHas('identityType', fn ($q) => $q->where('is_system', true))
            ->first();

        if (! $superAdmin) {
            return ['ok' => false, 'error' => 'no_super_admin'];
        }

        $portalKey = (string) ($payload['portal_key'] ?? 'admin');
        $portal = Portal::query()->where('portal_key', $portalKey)->first();

        if (! $portal) {
            return ['ok' => false, 'error' => 'portal_not_found'];
        }

        // 超級管理員不受端口 admin_only 限制（見 7.2 登入規則第 1 點），因此
        // 不需要再走 resolvePortalAccess，直接核發即可。
        $issued = $this->auth->issueSession($superAdmin->id, $portal->id, null, 'hq_impersonation');

        // 見 15 節第 3、5、6 點：跳轉登入屬於必須審計的操作，寫入該子站自己
        // 的 audit_logs（不是主站的），記「誰、何時、透過此機制進入了哪個
        // 端口」；requested_by_hq_user_id 是主站操作者的 hq_users.id，兩個
        // 資料庫各自一份紀錄、不跨庫 join（見 15 節第 5 點）。
        AuditLog::query()->create([
            'user_id' => $superAdmin->id,
            'action' => 'hq_impersonation.login',
            'target' => "portal:{$portal->portal_key}",
            'context' => ['requested_by_hq_user_id' => $payload['requested_by'] ?? null],
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return [
            'ok' => true,
            'token' => $issued['token'],
            'expiresAt' => $issued['expires_at'],
            'portalKey' => $portal->portal_key,
            'userId' => $superAdmin->id,
        ];
    }
}
