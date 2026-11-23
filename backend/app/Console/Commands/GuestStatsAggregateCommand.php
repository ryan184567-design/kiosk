<?php

/* *****************************************
File: GuestStatsAggregateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 彙整訪客每日統計到緩存表 guest_daily_stats；預設彙整前一日，每天凌晨 00:05 自動排程執行（routes/console.php）
***************************************** */

namespace App\Console\Commands;

use App\Api\Guest\Services\GuestStatService;
use App\Hq\Site\Models\Site;
use App\Support\Hq\HqPresence;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// 沒帶 --site：單站模式彙整自己；多站模式遍歷所有啟用的子站（排程用，比照 factory:logs-prune）。
// 沒帶日期：彙整前一日；--date 指定單日；--from／--to 補一段區間（上線補歷史資料、修正來源資料後重算）。
// 同一天重跑會先刪後寫，結果一致。需 cron 每分鐘跑 schedule:run（DEVELOP.MD 18.4）。
class GuestStatsAggregateCommand extends Command
{
    protected $signature = 'guest:stats-aggregate
        {--site= : 只彙整指定子站（多站模式）；不帶則遍歷全部啟用的子站}
        {--date= : 彙整指定日期 YYYY-MM-DD，預設前一日}
        {--from= : 補資料區間起 YYYY-MM-DD，需搭配 --to}
        {--to= : 補資料區間迄 YYYY-MM-DD，需搭配 --from}';

    protected $description = '彙整訪客每日統計到緩存表 guest_daily_stats（預設前一日）';

    public function handle(GuestStatService $stats): int
    {
        $range = $this->resolveRange();
        if ($range === null) {
            return self::FAILURE;
        }

        [$from, $to] = $range;

        if (! HqPresence::available()) {
            $this->bindConnection(SiteDatabaseConfig::singleSiteConnection());
            $this->aggregateRange($stats, (string) config('multisite.single_site.template_namespace', 'site'), $from, $to);

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
            $this->aggregateRange($stats, (string) $site->code, $from, $to);
        }

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function resolveRange(): ?array
    {
        try {
            if ($this->option('from') || $this->option('to')) {
                if (! $this->option('from') || ! $this->option('to')) {
                    $this->error('--from 與 --to 必須一起使用。');

                    return null;
                }
                $from = Carbon::createFromFormat('!Y-m-d', $this->option('from'));
                $to = Carbon::createFromFormat('!Y-m-d', $this->option('to'));
            } else {
                $from = $to = $this->option('date')
                    ? Carbon::createFromFormat('!Y-m-d', $this->option('date'))
                    : Carbon::yesterday();
            }
        } catch (\Throwable) {
            $this->error('日期格式必須是 YYYY-MM-DD。');

            return null;
        }

        if ($from->gt($to)) {
            $this->error('起始日期不可晚於結束日期。');

            return null;
        }

        return [$from, $to];
    }

    private function aggregateRange(GuestStatService $stats, string $label, Carbon $from, Carbon $to): void
    {
        $rows = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $rows += $stats->aggregate($day);
        }

        $this->info("[{$label}] 已彙整 {$from->toDateString()} ~ {$to->toDateString()}，寫入 {$rows} 列。");
    }

    private function bindConnection(array $connection): void
    {
        Config::set('database.connections.site_current', $connection);
        DB::purge('site_current');
    }
}
