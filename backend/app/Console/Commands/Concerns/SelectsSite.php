<?php

/* *****************************************
File: SelectsSite.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 共用給「單一站點層級」的指令（permission:sync-api/sync-web/audit-ceiling 等） 用，統一提供 --site= 選項並切換 site_current 連線。
***************************************** */

namespace App\Console\Commands\Concerns;

use App\Hq\Site\Models\Site;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 共用給「單一站點層級」的指令（permission:sync-api/sync-web/audit-ceiling 等）
// 用，統一提供 --site= 選項並切換 site_current 連線。這幾支指令本身只查
// site_current（不像 site:migrate 那樣本來就要遍歷全部站點），需要呼叫端
// 明確指定要對哪個站點操作，不能假設「目前預設連線剛好是對的」。
trait SelectsSite
{
    protected function resolveSiteOrFail(): ?Site
    {
        // 單站部署包沒有站點清單可選（見 HqPresence），--site 沒有意義；維持
        // site_current 佔位設定即可——單站模式下它會由 EnsureSiteContext 對照
        // 預設連線（見 SiteResolver::singleSiteConfig），而這幾支指令查的是
        // 預設連線（子站資料庫就是預設連線），本來就不需要切換。
        if (! HqPresence::available()) {
            return null;
        }

        $siteId = $this->option('site');

        if (! $siteId) {
            $this->error('請帶 --site={site_id} 指定要操作哪個子站。');

            return null;
        }

        $site = Site::query()->find($siteId);

        if (! $site) {
            $this->error("找不到站點：{$siteId}");

            return null;
        }

        Config::set('database.connections.site_current', SiteDatabaseConfig::connection($site->db_name));
        DB::purge('site_current');

        return $site;
    }
}
