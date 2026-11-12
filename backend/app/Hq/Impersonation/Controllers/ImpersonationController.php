<?php

/* *****************************************
File: ImpersonationController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站跳轉登入子站：讓主站管理員不需要另外知道每個子站的帳密，就能以 該子站超級管理員身份直接進入除錯/協助客戶。
             核發交換碼與落地頁網址的邏輯在 App\Hq\Impersonation\Services\ImpersonationService。
***************************************** */

namespace App\Hq\Impersonation\Controllers;

use App\Hq\Impersonation\Services\ImpersonationService;
use App\Hq\Site\Models\Site;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseHqController;
use Illuminate\Http\Request;

// 主站跳轉登入子站：讓主站管理員不需要另外知道每個子站的帳密，就能以
// 該子站超級管理員身份直接進入除錯/協助客戶。要求呼叫者是主站超級管理員
// （路由掛 hq.jwt.auth + hq.super_admin）。
class ImpersonationController extends BaseHqController
{
    /**
     * @api-method POST
     * @api-path /api/hq/sites/{siteId}/impersonate-token
     * @api-tag Impersonation
     * @api-summary 核發跳轉登入交換碼
     * @api-description
     * 主站管理員在「子站管理」畫面按「跳轉登入子站」時呼叫這支。流程分兩段：
     * 這支先核發一組一次性的隨機交換碼（不是直接給子站 JWT），並回傳子站端
     * 落地頁的完整網址，前端拿到後直接導頁過去；子站端的落地頁
     * `GET /hq-impersonate/callback` 會把交換碼換成真正能用的子站 JWT，
     * 交給管理後台 SPA 並導向該端口後台首頁——分兩段是因為交換碼要跟著
     * 網址參數明碼傳輸、經過瀏覽器導頁，直接放真正的 JWT 風險較高，交換碼
     * 只在極短時間窗口（60 秒）內有效且一次即失效，即使外洩也難以被利用。
     * 交換碼存在共用 cache（不分子站，因為核發跟兌換是兩個不同的應用
     * 各自連自己的資料庫，需要一個雙方都連得到的共用位置），60 秒後自動
     * 過期。若指定站點還沒建置完成（`provision_status` 不是 `ready`），
     * 直接拒絕核發，避免導去一個還不能用的子站。
     * 兌換出來的身份固定是目標子站「`identity_types.is_system=true`」的
     * 超級管理員（每個子站 baseline 建站時都一定會建立這個帳號），不是
     * 主站管理員自己在該站的帳號（因為主站管理員在子站的資料庫裡根本
     * 沒有對應帳號，兩邊使用者體系完全獨立）。超級管理員不受任何端口
     * `admin_only` 限制，可以核發任何 `portal_key` 的身份進入。
     * `portal_key` 是否真的存在，由子站端兌換時查該站的 `portals` 表判斷
     * （主站不查子站資料庫，見 6.4「執行期不會查詢主站資料庫」的相反方向
     * 同樣成立）——帶了子站沒有的端口，會在落地頁上看到「端口不存在」。
     * @api-request-field portal_key string 要以哪個端口身份進入子站，省略時預設為 admin
     * @api-request-example
     * {"portal_key": "admin"}
     * @api-response-field data.code string 一次性交換碼，60 秒內有效，用過即失效
     * @api-response-field data.expires_in int 交換碼有效秒數，固定為 60
     * @api-response-field data.site_code string 目標子站代碼
     * @api-response-field data.redirect_url string 子站端落地頁完整網址（已帶上交換碼），主站前端直接據此導頁即可；已綁定網域的子站會用該網域，只有路徑前綴的會用「目前網域 + /{code}」（見 6.1）
     */
    public function issueToken(Request $request, int $siteId, ImpersonationService $impersonation)
    {
        $data = $this->validateInput($request, [
            'portal_key' => 'nullable|string',
        ]);

        $site = Site::query()->find($siteId);

        if (! $site || $site->provision_status !== 'ready') {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('hq.impersonation.site_not_ready'));
        }

        // 入參留空或未帶一律套用預設端口 admin，與 6.11 第 1 點「預設 admin」一致。
        $portalKey = trim((string) ($data['portal_key'] ?? '')) ?: 'admin';

        return $this->success($impersonation->issue(
            $request,
            $site,
            $portalKey,
            $this->currentHqUser($request)?->id,
        ));
    }
}
