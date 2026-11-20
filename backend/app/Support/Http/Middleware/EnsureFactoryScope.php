<?php

/* *****************************************
File: EnsureFactoryScope.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: factory.scope:{scope1},{scope2}...：App 需擁有「任一」所列 scope，需排在 factory.auth 之後
***************************************** */

namespace App\Support\Http\Middleware;

use App\Api\Factory\Models\FactoryLog;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Factory\FactoryScopeMatcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFactoryScope
{
    public function __construct(private readonly FactoryScopeMatcher $matcher) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $app = $request->attributes->get('factory_app');

        if (! $app) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING);
        }

        $granted = $this->matcher->granted($app);

        foreach ($scopes as $scope) {
            if ($this->matcher->allows($granted, $scope)) {
                return $next($request);
            }
        }

        // 帶上模塊與具體方法，後台才能依「業務模塊／具體方法」搜到這筆被拒記錄
        FactoryLog::record('scope_denied', false, $app->app_id, $request->ip(), implode(',', $scopes), null, FactoryLog::routeInfo($request));

        return ApiResponder::fail(ErrorCode::PERMISSION_DENIED);
    }
}
