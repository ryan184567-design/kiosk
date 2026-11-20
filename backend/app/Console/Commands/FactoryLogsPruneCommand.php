<?php

/* *****************************************
File: FactoryLogsPruneCommand.php
Author: cliffdean
Date: 2026-11-20
Description: 清理超過保留天數（預設 30 天）的廠商模式記錄，每天自動排程執行，見 docs/APP_DEVELOP.MD 7.5
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use App\Support\Factory\FactoryLogPruner;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 沒帶 --site：單站模式清自己；多站模式遍歷所有啟用的子站（排程用，比照 jwt:sessions:prune）。
// 帶 --site=ID：只清指定子站。排程見 routes/console.php（需 cron 每分鐘跑 schedule:run，DEVELOP.MD 18.4）。
class FactoryLogsPruneCommand extends Command
{
    protected $signature = 'factory:logs-prune
        {--site= : 只清理指定子站（多站模式）；不帶則遍歷全部啟用的子站}
        {--days= : 保留天數，預設取 FACTORY_LOG_RETENTION_DAYS（30）}
        {--dry-run : 只預覽筆數，不實際刪除}';

    protected $description = '清理超過保留天數的廠商模式記錄（見 docs/APP_DEVELOP.MD 7.5）';

    public function handle(FactoryLogPruner $pruner): int
    {
        $days = (int) ($this->option('days') ?? config('factory.log_retention_days'));
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('保留天數必須 >= 1。');

            return self::FAILURE;
        }

        if (! HqPresence::available()) {
            $this->bindConnection(SiteDatabaseConfig::singleSiteConnection());
            $this->report((string) config('multisite.single_site.template_namespace', 'site'), $pruner->prune($days, $dryRun), $days, $dryRun);

            return self::SUCCESS;
        }

        $sites = Site::query()->where('is_enabled', true)
            ->when($this->option('site'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($sites->isEmpty()) {
            $this->error($this->option('site') ? "找不到啟用中的站點：{$this->option('site')}" : '找不到任何啟用的子站。');

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $this->bindConnection(SiteDatabaseConfig::connection($site->db_name));
            $this->report((string) $site->code, $pruner->prune($days, $dryRun), $days, $dryRun);
        }

        return self::SUCCESS;
    }

    private function bindConnection(array $connection): void
    {
        Config::set('database.connections.site_current', $connection);
        DB::purge('site_current');
    }

    private function report(string $label, int $count, int $days, bool $dryRun): void
    {
        $dryRun
            ? $this->line("[{$label}] 預覽：超過 {$days} 天的記錄 {$count} 筆會被移除。")
            : $this->info("[{$label}] 已移除超過 {$days} 天的記錄 {$count} 筆。");
    }
}
