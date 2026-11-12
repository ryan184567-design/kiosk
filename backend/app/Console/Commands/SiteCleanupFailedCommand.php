<?php

/* *****************************************
File: SiteCleanupFailedCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 建站失敗補償，見 README 6.3「資源耗盡與失敗補償」第 4 點：不自動靜默重試， 人工確認後才清理。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// 建站失敗補償，見 README 6.3「資源耗盡與失敗補償」第 4 點：不自動靜默重試，
// 人工確認後才清理。
class SiteCleanupFailedCommand extends Command
{
    protected $signature = 'site:cleanup-failed {site_id? : 只清理指定站點，不帶則列出全部 failed 站點}';

    protected $description = '清理建站失敗留下的孤兒資料庫與設定檔（見 README 6.3）';

    public function handle(): int
    {
        $query = Site::query()->where('provision_status', 'failed');

        if ($siteId = $this->argument('site_id')) {
            $query->where('site_id', $siteId);
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->info('沒有 provision_status=failed 的站點。');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $this->line("站點：{$site->code}（site_id={$site->site_id}）　原因：{$site->provision_failed_reason}");

            if (! $this->confirm('確定要清理這個失敗站點（刪除資料庫與設定檔）？', false)) {
                $this->warn('已跳過。');

                continue;
            }

            $this->cleanup($site);
        }

        return self::SUCCESS;
    }

    private function cleanup(Site $site): void
    {
        try {
            DB::connection('mysql_provision')->statement("DROP DATABASE IF EXISTS `{$site->db_name}`");
        } catch (\Throwable $e) {
            $this->warn("刪除資料庫時出錯（可能本來就沒建成）：{$e->getMessage()}");
        }

        File::delete(storage_path("app/sites/{$site->site_id}.php"));

        $indexPath = storage_path('app/sites/sites.json');
        if (File::exists($indexPath)) {
            $index = json_decode(File::get($indexPath), true) ?? [];
            unset($index[$site->site_id]);
            File::put($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $site->delete();

        $this->info("已清理：site_id={$site->site_id}");
    }
}
