<?php

/* *****************************************
File: EnsurePermission.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: permission.api:{code} middleware，見 README 7.4、18.2.1。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Permission\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// permission.api:{code} middleware，見 README 7.4、18.2.1。要求先過
// jwt.auth 才會有 auth_user/auth_portal_id 可用，路由掛載順序見範例。
class EnsurePermission
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->attributes->get('auth_user');
        $portalId = $request->attributes->get('auth_portal_id');

        if (! $user || ! $portalId) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        if (! $this->permissions->canAny($user, $portalId, $codes)) {
            return ApiResponder::fail(ErrorCode::PERMISSION_DENIED);
        }

        return $next($request);
    }
}
