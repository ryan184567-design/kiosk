<?php

/* *****************************************
File: EnsureJwtAuthenticated.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站 JWT 驗證，見 README 11.1、7.2。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Api\User\Models\User;
use App\Api\Auth\Models\UserJwtSession;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Auth\JwtService;
use App\Support\Factory\FactoryJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 子站 JWT 驗證，見 README 11.1、7.2。驗證通過後把目前使用者/端口/角色
// 放進 request attributes，供 BaseApiController 的 currentUser() 等方法取用。
class EnsureJwtAuthenticated
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 見 README 8.6：Vue3 SPA／一般 API 呼叫走 bearer header（優先判斷）；
        // Blade／SSO 落地頁是瀏覽器直接導頁，帶不了自訂 header，改用 cookie
        // 承載同一份 JWT，兩者只是「token 從哪裡讀」不同，驗證邏輯完全共用。
        $token = $request->bearerToken() ?? $request->cookie((string) config('jwt.cookie_name'));

        if (! $token) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        $claims = $this->jwt->decode($token, (string) config('jwt.site_secret'));

        if (! $claims) {
            // 見 DEVELOP.MD 12.8：App Token（廠商模式）只允許訪問 /api/factory/*，
            // 拿它來呼叫一般（用戶）接口要回 403，而不是當成「token 無效」的 401。
            if (app(FactoryJwtService::class)->verify($token) !== null) {
                return ApiResponder::fail(ErrorCode::PERMISSION_DENIED);
            }

            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING, __('api.auth.token_invalid'));
        }

        $session = UserJwtSession::query()->where('jti', $claims->jti)->where('is_active', true)->first();

        if (! $session) {
            // 這裡涵蓋兩種情況：11.1 單帳號單 Token 被新登入取代、或使用者自己登出，
            // 兩者在資料庫層面都只是 is_active=false，無法細分原因，訊息用中性字眼。
            return ApiResponder::fail(ErrorCode::TOKEN_REPLACED_BY_NEW_LOGIN, __('api.auth.session_replaced'));
        }

        $user = User::query()->find($claims->sub);

        if (! $user) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        $this->touchSession($session, $request);

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_portal_id', (int) $claims->portal_id);
        $request->attributes->set('auth_role_id', $claims->role_id ?? null);
        $request->attributes->set('jwt_session', $session);

        return $next($request);
    }

    // 節流落庫，見 11.1 的 JWT_SESSION_TOUCH_INTERVAL_SECONDS：避免每次請求都更新資料表。
    private function touchSession(UserJwtSession $session, Request $request): void
    {
        $interval = (int) config('jwt_session.touch_interval_seconds');

        if ($session->last_access_at && $session->last_access_at->diffInSeconds(now()) < $interval) {
            return;
        }

        $session->update([
            'last_access_at' => now(),
            'last_access_ip' => $request->ip(),
            'last_access_user_agent' => $request->userAgent(),
        ]);
    }
}
