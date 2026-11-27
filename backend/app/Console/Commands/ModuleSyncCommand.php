<?php

/* *****************************************
File: ModuleSyncCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 掃描各模組目錄下的 module.json 並同步主站 modules 表，再把選單同步到各子站，見 README 5.1、6.9。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use App\Support\Hq\HqPresence;
use App\Support\Menu\MenuCacheVersion;
use App\Support\Module\ModuleRegistryService;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

// 掃描 app/Api/*/module.json 並同步主站 modules 表，見 README 6.9。
//
// 2026-11-27 起同時把 module.json 宣告的選單同步到每個子站：原本選單只在「重跑 baseline
// seed」或「主站開通模組」時才寫進子站，事後在 module.json 新增選單（例如訪客模組的「公司
// 帳號管理」），拉了更新只跑 module:sync 的人永遠看不到。現在照 README 5.1「改 module.json
// → module:sync」就會生效。只補該站「已開通模組」的選單，邏輯與開通模組時相同
// （ModuleRegistryService::provisionMenusForSite）；會以 module.json 為準覆寫模組選單的
// 標題、圖示、排序（不影響後台手動新增的選單）。不想動子站時加 --no-menus。
class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync
        {--no-menus : 只同步主站 modules 表，不同步各子站的選單}';

    protected $description = '掃描 app/Api/*/module.json，同步主站 modules 表與各子站的模組選單（見 README 5.1、6.9）';

    public function handle(ModuleRegistryService $registry, MenuCacheVersion $menuCache): int
    {
        try {
            $codes = $registry->syncToDatabase();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($codes as $code) {
            $this->line("同步：{$code}");
        }

        $this->info('共同步 '.count($codes).' 個模組。');

        if ($this->option('no-menus') || ! HqPresence::available()) {
            return self::SUCCESS;
        }

        return $this->syncSiteMenus($registry, $menuCache);
    }

    // 逐站切換到子站自己的資料庫（同 SiteModuleController::provisionSiteMenus），補上已開通模組的選單，
    // 並讓該站選單快取失效，使用者重新整理就看得到。某一站失敗不影響其他站。
    private function syncSiteMenus(ModuleRegistryService $registry, MenuCacheVersion $menuCache): int
    {
        $sites = Site::query()->where('is_enabled', true)->where('provision_status', 'ready')->orderBy('site_id')->get();
        $failed = 0;

        foreach ($sites as $site) {
            try {
                Config::set('database.connections.site_current', SiteDatabaseConfig::connection($site->db_name));
                DB::purge('site_current');

                $registry->provisionMenusForSite($registry->enabledCodesForSite($site));
                $menuCache->bump($site->site_id);

                $this->line("選單同步：{$site->code}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("選單同步失敗（{$site->code}）：{$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
