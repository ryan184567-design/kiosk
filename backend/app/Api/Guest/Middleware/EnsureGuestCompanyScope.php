<?php

/* *****************************************
File: EnsureGuestCompanyScope.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 登入 company 端口時，找出使用者所屬公司並套上資料範圍；不是公司成員一律拒絕。
***************************************** */

namespace App\Api\Guest\Middleware;

use App\Api\Guest\Models\GuestCompanyMember;
use App\Api\Guest\Services\GuestCompanyService;
use App\Api\Guest\Support\GuestCompanyContext;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Portal\Models\Portal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 必須掛在 jwt.auth 之後（要讀 auth_user／auth_portal_id）。
// 公司端口的資料隔離只靠這裡＋GuestApply 的全域範圍，不依賴前端傳什麼參數：
// 即使有人把 company 端口的「端口管理員」身分給了某個帳號，沒有公司成員紀錄一樣進不來。
class EnsureGuestCompanyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('auth_user');
        $portalId = $request->attributes->get('auth_portal_id');

        if (! $user || ! $portalId) {
            return $next($request);
        }

        $portalKey = Portal::query()->whereKey($portalId)->value('portal_key');

        if ($portalKey !== GuestCompanyService::PORTAL_KEY) {
            return $next($request);
        }

        $member = GuestCompanyMember::query()->with('doorplate')->where('user_id', $user->id)->first();

        if (! $member || ! $member->doorplate) {
            return ApiResponder::fail(ErrorCode::PERMISSION_DENIED, __('api.guest_company.not_member'));
        }

        $request->attributes->set(GuestCompanyContext::ATTRIBUTE, $member);

        return $next($request);
    }
}
