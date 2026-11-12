<?php

/* *****************************************
File: SiteModuleController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站模塊開通/下架：控制某個子站能不能使用某個功能模塊（依購買方案開通）， 這裡改的是「該模塊在該站是否可被存取」，不影響該模塊的資料表/資料本身 （關閉後資料還在，重新開通即可繼續使用，不用重新設計資料隔離）。
***************************************** */

namespace App\Hq\SiteModule\Controllers;

use App\Support\Audit\Models\HqAuditLog;
use App\Hq\SiteModule\Models\Module;
use App\Hq\Site\Models\Site;
use App\Hq\SiteModule\Models\SiteModule;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseHqController;
use App\Support\Menu\MenuCacheVersion;
use App\Support\Module\ModuleRegistryService;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 子站模塊開通/下架：控制某個子站能不能使用某個功能模塊（依購買方案開通），
// 這裡改的是「該模塊在該站是否可被存取」，不影響該模塊的資料表/資料本身
// （關閉後資料還在，重新開通即可繼續使用，不用重新設計資料隔離）。全部要求
// 呼叫者是主站超級管理員（路由掛 hq.jwt.auth + hq.super_admin）。
class SiteModuleController extends BaseHqController
{
    /**
     * @api-method GET
     * @api-path /api/hq/sites/{siteId}/modules
     * @api-tag SiteModule
     * @api-summary 列出指定子站的模塊開通狀態
     * @api-description
     * 回傳系統裡登記過的所有模塊（`modules` 主表，依 sort 排序），逐一標示
     * 這個子站目前有沒有開通。核心模塊（`is_core=true`，例如登入、選單管理
     * 這種每個子站都一定要有的基礎功能）恆為開通，不受個別開通/關閉紀錄
     * 影響；非核心模塊要在 `site_modules` 表有一筆 `enabled_at` 已設定、
     * `disabled_at` 為 null 的紀錄才算開通。找不到指定站點時回傳標準
     * 404（Laravel `findOrFail` 內建行為）。
     * @api-no-request-body
     * @api-response-field data.site.site_id int 站點ID
     * @api-response-field data.site.code string 站點代碼
     * @api-response-field data.site.name string 站點顯示名稱
     * @api-response-field data.modules[].code string 模塊代碼
     * @api-response-field data.modules[].name string 模塊顯示名稱
     * @api-response-field data.modules[].is_core bool 是否為核心模塊（恆為開通，不能被關閉）
     * @api-response-field data.modules[].enabled bool 此模塊在這個子站目前是否可用
     */
    public function index(int $siteId)
    {
        $site = Site::query()->findOrFail($siteId);

        $modules = Module::query()->orderBy('sort')->get()->map(function (Module $module) use ($siteId) {
            $siteModule = SiteModule::query()
                ->where('site_id', $siteId)
                ->where('module_code', $module->code)
                ->first();

            return [
                'code' => $module->code,
                'name' => $module->name,
                'is_core' => $module->is_core,
                'enabled' => $module->is_core || ($siteModule && $siteModule->enabled_at && ! $siteModule->disabled_at),
            ];
        });

        return $this->success(['site' => $site->only(['site_id', 'code', 'name']), 'modules' => $modules]);
    }

    /**
     * @api-method POST
     * @api-path /api/hq/sites/{siteId}/modules/{moduleCode}/enable
     * @api-tag SiteModule
     * @api-summary 開通模塊
     * @api-description
     * 讓指定子站可以開始使用某個模塊，寫入/更新 `site_modules` 一筆紀錄
     * （`enabled_at` 設為現在、`disabled_at` 清成 null）。核心模塊
     * （`modules.is_core=true`）本來就恆為開通，對核心模塊呼叫這支會直接
     * 回錯誤，不會建立多餘紀錄。
     * **依賴連動**：這個模塊的 `module.json` 若宣告 `depends`，這裡會把
     * 依賴的模塊一併開通（遞移展開，見
     * `App\Support\Module\ModuleRegistryService::dependencyClosure`），
     * 回應的 `cascaded_modules` 列出因此連動開通的模塊代碼（不含這支本身
     * 要開通的那個），方便後台明確告知管理員「連帶開通了什麼」，不是靜默
     * 動作。
     * **選單即時註冊**：開通後會切到該子站自己的資料庫，依這個站目前
     * 完整的啟用模組集合（含這次連動開通的）重新跑一次
     * `ModuleRegistryService::provisionMenusForSite()`——新開通模塊在
     * `module.json` 宣告的選單項目立即寫入該站的 `menus` 表，不用等重跑
     * seeder；同時讓該站選單快取失效，下一次載入選單會重新計算。這是
     * 主站對子站的跨庫操作：`site_modules` 異動與審計紀錄寫在主站自己的
     * 資料庫，選單則寫進子站自己的資料庫。
     * @api-no-request-body
     * @api-response-field data.cascaded_modules[] string 因依賴關係一併連動開通的模塊代碼（不含這支本身要開通的那個）
     */
    public function enable(Request $request, int $siteId, string $moduleCode, ModuleRegistryService $registry)
    {
        $module = Module::query()->where('code', $moduleCode)->first();

        if (! $module) {
            return $this->fail(ErrorCode::MODULE_NOT_ENABLED, __('hq.module.not_registered', ['code' => $moduleCode]));
        }

        if ($module->is_core) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('hq.module.core_always_enabled'));
        }

        $site = Site::query()->findOrFail($siteId);

        // 依賴遞移展開：買了 A、A 依賴 B，B 沒有主動開通過也要一起開通，
        // 不然 A 的選單/API 會指向一個實際上還沒開通的模塊。
        $toEnable = array_filter(
            $registry->dependencyClosure([$moduleCode]),
            fn (string $code) => ! Module::query()->where('code', $code)->value('is_core')
        );
        $cascaded = array_values(array_diff($toEnable, [$moduleCode]));

        foreach ($toEnable as $code) {
            SiteModule::query()->updateOrCreate(
                ['site_id' => $siteId, 'module_code' => $code],
                ['enabled_at' => now(), 'disabled_at' => null]
            );
        }

        app(MenuCacheVersion::class)->bump($siteId);
        $this->provisionSiteMenus($site, $registry);

        HqAuditLog::query()->create([
            'user_id' => $this->currentHqUser($request)?->id,
            'action' => 'module.enable',
            'target' => "site:{$siteId}/module:{$moduleCode}",
            'context' => ['site_id' => $siteId, 'module_code' => $moduleCode, 'cascaded_modules' => $cascaded],
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return $this->success(['cascaded_modules' => $cascaded]);
    }

    /**
     * @api-method POST
     * @api-path /api/hq/sites/{siteId}/modules/{moduleCode}/disable
     * @api-tag SiteModule
     * @api-summary 下架模塊
     * @api-description
     * 讓指定子站不能再使用某個模塊（寫入 `site_modules.disabled_at`）。
     * 核心模塊不能被下架，呼叫會直接回錯誤。**依賴保護**：這個站目前若
     * 還有其他已開通、非核心的模塊 `depends` 到這個要下架的模塊，這支會
     * 直接拒絕並在錯誤訊息列出是哪些模塊擋住，要求先下架那些模塊——不
     * 做連動下架，避免把管理員沒有主動要求關閉的功能一起關掉。下架純粹
     * 是功能鎖定：該模塊相關的資料表、已產生的業務資料、`menus` 表裡已
     * 註冊的選單列完全不受影響（見 README 6.9 第 5 點「模塊關閉不影響
     * 資料」，選單的可見性本來就已經由 `MenuService` 的模塊開通檢查即時
     * 擋下，不需要額外刪除選單列），重新開通後原封不動可以繼續使用。跟
     * enable 一樣會讓該站選單快取立即失效。
     * @api-no-request-body
     * @api-response-empty
     */
    public function disable(Request $request, int $siteId, string $moduleCode, ModuleRegistryService $registry)
    {
        $module = Module::query()->where('code', $moduleCode)->first();

        if (! $module) {
            return $this->fail(ErrorCode::MODULE_NOT_ENABLED, __('hq.module.not_registered', ['code' => $moduleCode]));
        }

        if ($module->is_core) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('hq.module.core_always_enabled'));
        }

        $site = Site::query()->findOrFail($siteId);

        $blockedBy = $this->findEnabledDependents($site, $moduleCode, $registry);

        if ($blockedBy !== []) {
            return $this->fail(
                ErrorCode::VALIDATION_FAILED,
                __('hq.module.disable_blocked_by_dependents', ['codes' => implode('、', $blockedBy)])
            );
        }

        SiteModule::query()->updateOrCreate(
            ['site_id' => $siteId, 'module_code' => $moduleCode],
            ['disabled_at' => now()]
        );

        app(MenuCacheVersion::class)->bump($siteId);

        HqAuditLog::query()->create([
            'user_id' => $this->currentHqUser($request)?->id,
            'action' => 'module.disable',
            'target' => "site:{$siteId}/module:{$moduleCode}",
            'context' => ['site_id' => $siteId, 'module_code' => $moduleCode],
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return $this->success();
    }

    // 這個站目前已開通、非核心、且 depends 到 $moduleCode 的模塊代碼——
    // 有任何一個，$moduleCode 就不能被下架（見 disable() 說明）。
    private function findEnabledDependents(Site $site, string $moduleCode, ModuleRegistryService $registry): array
    {
        $manifests = $registry->scanManifests();
        $enabled = $registry->enabledCodesForSite($site);
        $blockedBy = [];

        foreach ($manifests as $code => $manifest) {
            if ($code === $moduleCode || $manifest['is_core']) {
                continue;
            }

            if (in_array($moduleCode, $manifest['depends'], true) && in_array($code, $enabled, true)) {
                $blockedBy[] = $code;
            }
        }

        return $blockedBy;
    }

    // 切到目標子站自己的資料庫（見 App\Support\Site\SiteDatabaseConfig，
    // config:cache 安全的寫法，不是執行期讀 env()），依這個站目前完整的
    // 啟用模組集合重新註冊選單——upsert 是冪等操作，重跑不會產生重複列。
    private function provisionSiteMenus(Site $site, ModuleRegistryService $registry): void
    {
        Config::set('database.connections.site_current', SiteDatabaseConfig::connection($site->db_name));
        DB::purge('site_current');

        $registry->provisionMenusForSite($registry->enabledCodesForSite($site));
    }
}
