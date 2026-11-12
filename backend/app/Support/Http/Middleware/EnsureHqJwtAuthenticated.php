<?php

/* *****************************************
File: EnsureHqJwtAuthenticated.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站 JWT 驗證，見 README 6.12。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Hq\Auth\Models\HqUser;
use App\Hq\Auth\Models\HqUserJwtSession;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 主站 JWT 驗證，見 README 6.12。沒有端口概念，也沒有單帳號單 Token 的
// 限制（主站使用者少，範疇窄，非本階段重點，見 6.12 第 3 點的精簡設計）。
class EnsureHqJwtAuthenticated
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        $claims = $this->jwt->decode($token, (string) config('jwt.hq_secret'));

        if (! $claims) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING, __('hq.auth.token_invalid'));
        }

        $session = HqUserJwtSession::query()->where('jti', $claims->jti)->where('is_active', true)->first();

        if (! $session) {
            return ApiResponder::fail(ErrorCode::TOKEN_REPLACED_BY_NEW_LOGIN, __('hq.auth.session_replaced'));
        }

        $hqUser = HqUser::query()->find($claims->sub);

        if (! $hqUser || ! $hqUser->is_enabled) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        $session->update(['last_access_at' => now(), 'last_access_ip' => $request->ip()]);

        $request->attributes->set('auth_hq_user', $hqUser);
        $request->attributes->set('hq_jwt_session', $session);

        return $next($request);
    }
}
