<?php

/* *****************************************
File: SiteMigrateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站 Schema 同步策略，見 README 6.6：公版路徑固定套用，客製化站點專屬 migration 依慣例路徑自動偵測套用。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// 子站 Schema 同步策略，見 README 6.6：公版路徑固定套用，客製化站點專屬
// migration 依慣例路徑（database/sites/{code}/migrations）自動偵測套用，
// 不需要手動拼 --path。
class SiteMigrateCommand extends Command
{
    protected $signature = 'site:migrate
        {site_id? : 單一站點 ID，不帶則需搭配 --all}
        {--all : 對所有已啟用站點（含客製化）批次執行}
        {--path= : 明確覆寫成單一 migration 路徑（一般不需要）：不帶時自動套用公版路徑，且若該站在 database/sites/{code}/migrations 下有專屬 migration 也會一併套用}
        {--seed= : 一併執行的 seeder class}';

    protected $description = '同步子站 Schema（見 README 6.6）';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->migrateAllEnabled();
        }

        $siteId = $this->argument('site_id');

        if (! $siteId) {
            $this->error('請帶 site_id，或加 --all 批次執行所有已啟用站點。');

            return self::FAILURE;
        }

        $site = Site::query()->find($siteId);

        if (! $site) {
            $this->error("找不到站點：{$siteId}");

            return self::FAILURE;
        }

        return $this->migrateOne($site) ? self::SUCCESS : self::FAILURE;
    }

    private function migrateAllEnabled(): int
    {
        // site_type 只有 standard／customized 兩種（見 SiteProvisioningService），
        // 兩者都要跑公版 migration，客製化站點的專屬 migration 也會在
        // migrateOne() 裡自動偵測套用，這裡不用再依 site_type 篩選。
        $sites = Site::query()->where('is_enabled', true)->get();
        $failed = 0;

        foreach ($sites as $site) {
            $this->line("=== {$site->code}（site_id={$site->site_id}，{$site->site_type}）===");

            if (! $this->migrateOne($site)) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} 個站點失敗，其餘已完成。");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function migrateOne(Site $site): bool
    {
        try {
            Config::set('database.connections.site_current', SiteDatabaseConfig::connection($site->db_name));
            DB::purge('site_current');

            // migrate/db:seed 的 --database= 會永久改掉 config('database.default')，
            // 不會自己還原，見 SiteProvisioningService 的同一個註解與對話紀錄的踩雷案例。
            // 這裡跑批次（--all）時尤其重要，不還原的話下一個站點的沒寫死連線的
            // Model 會悄悄接到上一個站點的資料庫。
            $originalDefault = config('database.default');

            foreach ($this->resolvePaths($site) as $migrationPath) {
                Artisan::call('migrate', [
                    '--database' => 'site_current',
                    '--path' => $migrationPath,
                    '--force' => true,
                ], $this->getOutput());
            }

            if ($seeder = $this->option('seed')) {
                Artisan::call('db:seed', [
                    '--database' => 'site_current',
                    '--class' => $seeder,
                    '--force' => true,
                ], $this->getOutput());
            }

            Config::set('database.default', $originalDefault);

            $site->update([
                'last_migrated_at' => now(),
                'last_migration_batch' => (string) now()->timestamp,
            ]);

            $this->info("完成：{$site->code}");

            return true;
        } catch (\Throwable $e) {
            $this->error("失敗：{$site->code}　{$e->getMessage()}");

            return false;
        }
    }

    // 見 README 6.6、9.2：客製化站點專屬 migration 的存放路徑是固定慣例
    // （database/sites/{code}/migrations），不需要每次手動拼 --path——這裡
    // 直接依站代碼算出這個路徑，目錄存在就自動一併套用，客製化站點永遠是
    // 「公版 + 自己專屬」兩層都跑，不會漏掉、也不用記指令怎麼拼。
    // 明確帶 --path 時視為完全覆寫，只跑指定的這一個路徑，不會再自動加上
    // 公版或站點專屬路徑，供不遵循慣例路徑的特殊情境使用，一般不需要。
    private function resolvePaths(Site $site): array
    {
        if ($explicitPath = $this->option('path')) {
            return [$explicitPath];
        }

        $paths = ['database/migrations'];
        $sitePath = "database/sites/{$site->code}/migrations";

        if (File::isDirectory(base_path($sitePath))) {
            $paths[] = $sitePath;
        }

        return $paths;
    }
}
