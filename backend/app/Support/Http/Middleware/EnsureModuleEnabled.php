<?php

/* *****************************************
File: EnsureModuleEnabled.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: module:{code} middleware，見 README 6.9。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Hq\SiteModule\Models\Module;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Hq\HqPresence;
use App\Support\Module\ModuleAccessService;
use App\Support\Site\SiteContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// module:{code} middleware，見 README 6.9。modules/site_modules 存在主站
// 資料庫（預設連線），與 site_current 是兩個不同連線，可以在同一次請求裡
// 都查，不需要跨庫 join。實際開通判斷邏輯在 ModuleAccessService（與
// MenuService 共用，見 6.9 第 3 點雙重保險），這裡只多做「未登記」的
// 明確錯誤訊息。
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleAccessService $moduleAccess) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        // 單站部署包沒有 modules 主表（見 HqPresence）：不做「模塊登記」檢查，
        // 開通判斷直接交給 ModuleAccessService（單站一律視為可用，見該類別說明）。
        if (HqPresence::moduleRegistryAvailable()
            && ! Module::query()->where('code', $moduleCode)->exists()) {
            // 比照 6.9 第 4 點的錯誤處理原則：不可靜默放行。
            return ApiResponder::fail(ErrorCode::MODULE_NOT_ENABLED, __('api.module.not_registered', ['code' => $moduleCode]));
        }

        $siteId = app(SiteContext::class)->siteId;

        if (! $this->moduleAccess->isEnabledForSite($moduleCode, $siteId)) {
            return ApiResponder::fail(ErrorCode::MODULE_NOT_ENABLED);
        }

        return $next($request);
    }
}
