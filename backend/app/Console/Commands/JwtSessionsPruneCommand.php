<?php

/* *****************************************
File: JwtSessionsPruneCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: JWT 會話清理，見 README 11.1、18.4。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Auth\Models\HqUserJwtSession;
use App\Hq\Site\Models\Site;
use App\Api\Auth\Models\UserJwtSession;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// JWT 會話清理，見 README 11.1、18.4。清理主站自己的會話表，
// 再逐一切換連線清理每個子站的會話表（比照 site:migrate 的連線切換模式）。
class JwtSessionsPruneCommand extends Command
{
    protected $signature = 'jwt:sessions:prune {--dry-run : 只預覽筆數，不實際刪除}';

    protected $description = '清理過期 JWT 會話資料（見 README 11.1）';

    public function handle(): int
    {
        if (! config('jwt_session.prune_enabled')) {
            $this->warn('JWT_SESSION_PRUNE_ENABLED=false，未執行。');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays((int) config('jwt_session.prune_retention_days'));
        $dryRun = (bool) $this->option('dry-run');

        // 單站部署包沒有主站（見 HqPresence）：沒有 hq 會話表，也沒有站點清單
        // 可遍歷。這個部署包只服務一個站，直接清自己的 site_current 連線即可。
        if (! HqPresence::available()) {
            $this->pruneSingleSite($cutoff, $dryRun);

            return self::SUCCESS;
        }

        $this->pruneHq($cutoff, $dryRun);
        $this->pruneSites($cutoff, $dryRun);

        return self::SUCCESS;
    }

    // 單站模式：唯一一個站點的會話表就是預設連線指向的資料庫（見 README 6.8）。
    private function pruneSingleSite(Carbon $cutoff, bool $dryRun): void
    {
        // CLI 沒有經過 EnsureSiteContext，site_current 還是佔位設定（連到
        // 刻意不存在的假主機，見 config/database.php），要自己套上單站連線，
        // 見 SiteDatabaseConfig::singleSiteConnection。
        Config::set('database.connections.site_current', SiteDatabaseConfig::singleSiteConnection());
        DB::purge('site_current');

        $label = (string) config('multisite.single_site.template_namespace', 'site');

        $query = UserJwtSession::query()
            ->where('is_active', false)
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if ($dryRun) {
            $this->line("[{$label}] 預覽：{$count} 筆會被清理。");

            return;
        }

        $query->delete();
        $this->info("[{$label}] 已清理 {$count} 筆。");
    }

    private function pruneHq(Carbon $cutoff, bool $dryRun): void
    {
        $query = HqUserJwtSession::query()
            ->where('is_active', false)
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if ($dryRun) {
            $this->line("[hq] 預覽：{$count} 筆會被清理。");

            return;
        }

        $query->delete();
        $this->info("[hq] 已清理 {$count} 筆。");
    }

    private function pruneSites(Carbon $cutoff, bool $dryRun): void
    {
        $sites = Site::query()->where('is_enabled', true)->get();

        foreach ($sites as $site) {
            Config::set('database.connections.site_current', SiteDatabaseConfig::connection($site->db_name));
            DB::purge('site_current');

            $query = UserJwtSession::query()
                ->where('is_active', false)
                ->where('updated_at', '<', $cutoff);

            $count = $query->count();

            if ($dryRun) {
                $this->line("[{$site->code}] 預覽：{$count} 筆會被清理。");

                continue;
            }

            $query->delete();
            $this->info("[{$site->code}] 已清理 {$count} 筆。");
        }
    }
}
