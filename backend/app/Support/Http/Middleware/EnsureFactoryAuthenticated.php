<?php

/* *****************************************
File: EnsureFactoryAuthenticated.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: factory.auth：驗證 App Bearer JWT，與用戶 jwt.auth 並存互不影響
***************************************** */

namespace App\Support\Http\Middleware;

use App\Api\Factory\Models\FactoryApp;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Factory\FactoryCallLogger;
use App\Support\Factory\FactoryJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFactoryAuthenticated
{
    public function __construct(
        private readonly FactoryJwtService $jwt,
        private readonly FactoryCallLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $appId = $token ? $this->jwt->verify($token) : null;
        $app = $appId ? FactoryApp::query()->where('app_id', $appId)->first() : null;

        // token 有效、認得出是哪個 App（即使它已停用／過期／IP 不符），呼叫記錄才記得到它名下
        if ($app) {
            $request->attributes->set('factory_log_app', $app);
        }

        // 停用、過期、IP 不符、token 無效一律同一個 401，不透露原因
        if (! $app || ! $app->isUsable() || ! $app->ipAllowed($request->ip())) {
            return ApiResponder::fail(ErrorCode::CREDENTIALS_MISSING, __('api.auth.token_invalid'));
        }

        $request->attributes->set('factory_app', $app);

        return $next($request);
    }

    // 呼叫記錄放在 terminate（回應已確定之後）：連「例外轉成的 500 回應」也拿得到最終狀態碼與內容，
    // 而且寫記錄不拖慢回應。是否要記、記什麼見 FactoryCallLogger（App 的除錯模式）。
    public function terminate(Request $request, Response $response): void
    {
        $this->logger->record($request, $response, $request->attributes->get('factory_log_app'));
    }
}
