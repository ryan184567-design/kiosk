<?php

/* *****************************************
File: DashboardReportHeartbeatCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 每分鐘給儀錶板上報一次心跳（nsst 存活＋各訪客機最後回報時間與故障），由 routes/console.php 排程執行
***************************************** */

namespace App\Console\Commands;

use App\Api\Guest\Services\DashboardReportService;
use App\Hq\Site\Models\Site;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 每個子站各自一組上報設定：逐站檢查「是否啟用」與「上傳內容」有沒有勾心跳，符合才上報（比照 factory:logs-prune 的多站寫法）。
// 沒帶 --site：單站模式處理自己；多站模式遍歷所有啟用的子站。需 cron 每分鐘跑 schedule:run（DEVELOP.MD 18.4）。
class DashboardReportHeartbeatCommand extends Command
{
    protected $signature = 'dashboard:report-heartbeat {--site= : 只處理指定子站（多站模式）；不帶則遍歷全部啟用的子站}';

    protected $description = '給儀錶板上報一次心跳（含訪客機最後回報時間與故障），沒啟用或沒勾選心跳的子站略過';

    public function handle(DashboardReportService $service): int
    {
        if (! HqPresence::available()) {
            $this->bindConnection(SiteDatabaseConfig::singleSiteConnection());
            $this->report($service, (string) config('multisite.single_site.template_namespace', 'site'));

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
            $this->report($service, (string) $site->code);
        }

        return self::SUCCESS;
    }

    private function report(DashboardReportService $service, string $label): void
    {
        $setting = $service->settings();

        if (! $setting->shouldReport('heartbeat')) {
            $this->line("[{$label}] 未啟用心跳上報，略過。");

            return;
        }

        $ok = $service->reportHeartbeat();
        $ok ? $this->info("[{$label}] 心跳已上報。") : $this->warn("[{$label}] 心跳上報失敗（詳見 log）。");
    }

    private function bindConnection(array $connection): void
    {
        Config::set('database.connections.site_current', $connection);
        DB::purge('site_current');
    }
}
