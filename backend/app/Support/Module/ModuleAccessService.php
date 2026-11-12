<?php

/* *****************************************
File: ModuleAccessService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.9 第 3 點「雙重保險（API 與選單都要擋）」：兩處判斷邏輯 必須一致，抽成共用服務讓 EnsureModuleEnabled（API）、MenuService（選單） 呼叫同一套，避免各自實作導致邏輯分岔。
***************************************** */

namespace App\Support\Module;

use App\Hq\SiteModule\Models\Module;
use App\Hq\SiteModule\Models\SiteModule;
use App\Support\Hq\HqPresence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;


// 見 README 6.9 第 3 點「雙重保險（API 與選單都要擋）」：兩處判斷邏輯
// 必須一致，抽成共用服務讓 EnsureModuleEnabled（API）、MenuService（選單）
// 呼叫同一套，避免各自實作導致邏輯分岔。
class ModuleAccessService
{
    private ?array $singleSiteModuleCodes = null;

    public function isEnabledForSite(string $moduleCode, int $siteId): bool
    {
        // 單站部署包沒有主站（見 HqPresence）：模塊開通清單改讀匯出時固化的
        // 檔案（storage/app/site_modules.php），6.9 的購買授權在現場仍成立。
        if (! HqPresence::moduleRegistryAvailable()) {
            return in_array($moduleCode, $this->singleSiteModuleCodes(), true);
        }

        $module = Module::query()->where('code', $moduleCode)->first();

        if (! $module) {
            return false;
        }

        if ($module->is_core) {
            return true;
        }

        if (! $module->is_enabled) {
            return false;
        }

        return SiteModule::query()
            ->where('site_id', $siteId)
            ->where('module_code', $moduleCode)
            ->whereNotNull('enabled_at')
            ->whereNull('disabled_at')
            ->exists();
    }

    // 單站部署包已開通的模塊代碼（匯出時固化，見 SitePackageService::
    // writeSiteModuleList）。檔案不存在時（例如舊版匯出包）回傳空陣列並
    // 記錄一次警告——這種情況會讓所有模塊被視為未開通，後台等同全鎖，
    // 因此寧可吵一次也不要默默讓人以為功能壞了。
    private function singleSiteModuleCodes(): array
    {
        if ($this->singleSiteModuleCodes !== null) {
            return $this->singleSiteModuleCodes;
        }

        $path = storage_path('app/site_modules.php');

        if (! File::exists($path)) {
            Log::warning('單站模式找不到模塊開通清單，所有模塊將被視為未開通。', ['path' => $path]);

            return $this->singleSiteModuleCodes = [];
        }

        return $this->singleSiteModuleCodes = (array) require $path;
    }
}
