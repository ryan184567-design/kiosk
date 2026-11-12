<?php

/* *****************************************
File: EnsureHqSuperAdmin.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.12 第 3 點：site:create、模塊開通/下架、跳轉登入等高風險操作 一律要求 hq_users.is_super_admin=true。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 見 README 6.12 第 3 點：site:create、模塊開通/下架、跳轉登入等高風險操作
// 一律要求 hq_users.is_super_admin=true。需排在 hq.jwt.auth 之後。
class EnsureHqSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $hqUser = $request->attributes->get('auth_hq_user');

        if (! $hqUser || ! $hqUser->is_super_admin) {
            return ApiResponder::fail(ErrorCode::PERMISSION_DENIED);
        }

        return $next($request);
    }
}
