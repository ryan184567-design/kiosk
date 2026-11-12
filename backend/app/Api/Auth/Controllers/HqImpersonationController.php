<?php

/* *****************************************
File: HqImpersonationController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站端兌換交換碼（JSON API），見 DEVELOP.MD 6.11 第 2-3 點。
             實際驗證／核發邏輯在 App\Support\Impersonation\HqImpersonationService，
             與瀏覽器導頁用的 Blade 落地頁共用（見該服務說明）。
***************************************** */

namespace App\Api\Auth\Controllers;

use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Impersonation\HqImpersonationService;
use App\Support\Site\SiteContext;
use Illuminate\Http\Request;

// 子站端兌換交換碼，見 DEVELOP.MD 6.11 第 2-3 點。不掛 jwt.auth（這本身
// 就是登入機制），但要掛 site.context 才知道目前是哪個站點。
class HqImpersonationController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/hq-impersonate/exchange
     * @api-tag Auth
     * @api-summary 主站跳轉登入兌換交換碼（JSON API）
     * @api-description
     * 主站管理員在主站後台按「跳轉登入子站」時，主站先核發一組一次性交換碼，
     * 瀏覽器帶著這組碼導頁到目標子站，這支就是程式化（非瀏覽器導頁）兌換
     * 交換碼的入口。查詢字串參數 `code`（一次性交換碼）驗證後立即從 cache
     * 刪除，同一組碼不能兌換第二次（防止重放攻擊）；交換碼核發後只有 60 秒
     * 有效期，過期後兌換一律視同無效碼；核發時已綁定了目標站點，拿著 A 站
     * 核發的碼到 B 站兌換會被拒絕（比對 `site_id` 不符）。
     * 兌換成功後核發的身份固定是「目標子站的超級管理員」（`identity_types.
     * is_system=true` 的帳號，每個子站在 baseline 建站時都一定會建立這個
     * 帳號），不是主站管理員自己的帳號——因為主站管理員在子站的資料庫裡
     * 根本沒有對應帳號，兩邊使用者體系完全獨立。這支不掛登入驗證中介層
     * （因為這支本身就是一種登入方式），但要掛 `site.context` 才知道目前
     * 兌換請求落在哪個子站，用來比對交換碼綁定的站點是否相符。
     *
     * **瀏覽器導頁請改用 Blade 落地頁 `GET /hq-impersonate/callback`**（見
     * 6.11 第 2 點）：這支回傳的是 JSON，瀏覽器直接導頁只會看到一頁 JSON、
     * 而且管理後台 SPA 的 JWT 存在 localStorage（見
     * resources/vue_src_admin/src/stores/auth.js），光靠 cookie 進不了後台；
     * 落地頁會把 token 同時寫進 cookie 與該 SPA 的 localStorage 再導向後台。
     * 這支保留給指令碼／自動化測試使用，兩者共用同一份兌換邏輯
     * （`App\Support\Impersonation\HqImpersonationService::redeem()`）。
     * @api-portal *
     * @api-response-field data.access_token string 子站 JWT（以該站超級管理員身份核發）
     * @api-response-field data.expires_at int 過期時間（unix timestamp）
     */
    public function exchange(Request $request, SiteContext $siteContext, HqImpersonationService $impersonation)
    {
        $result = $impersonation->redeem($request->query('code'), $siteContext->siteId, $request->ip());

        if (! $result['ok']) {
            // error 值即 lang/zh_TW/api.php 的 api.auth.* 尾段，見該服務說明。
            return $this->fail(ErrorCode::CREDENTIALS_MISSING, __("api.auth.{$result['error']}"));
        }

        return $this->success([
            'access_token' => $result['token'],
            'expires_at' => $result['expiresAt'],
        ]);
    }
}
