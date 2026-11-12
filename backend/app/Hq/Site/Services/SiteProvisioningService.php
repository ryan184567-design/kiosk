<?php

/* *****************************************
File: SiteProvisioningService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 自動建站流程的共用邏輯，見 README 6.3。
***************************************** */

namespace App\Hq\Site\Services;

use App\Hq\Site\Models\Site;
use App\Support\Site\SiteDatabaseConfig;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

// 自動建站流程的共用邏輯，見 README 6.3。CLI（site:create）與 HTTP
// （POST /api/hq/sites，經 Queue Job 非同步呼叫）共用同一份，避免兩邊邏輯
// 分岔。驗證失敗（code 格式/重複、type 不合法）直接拋例外中斷、不建立任何
// 紀錄；建站中途失敗（建庫/跑 migration）則寫入 provision_status=failed，
// 不重新拋出，讓非同步呼叫端（Job）不需要額外 try/catch。
class SiteProvisioningService
{
    /**
     * @throws InvalidArgumentException 驗證失敗，尚未建立任何紀錄
     */
    public function createPending(string $code, string $name, string $type): Site
    {
        if (! preg_match('/^[a-z0-9_]+$/', $code)) {
            throw new InvalidArgumentException(__('hq.provisioning.invalid_code_format'));
        }

        if (Site::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException(__('hq.provisioning.code_exists', ['code' => $code]));
        }

        if (! in_array($type, ['standard', 'customized'], true)) {
            throw new InvalidArgumentException(__('hq.provisioning.invalid_type'));
        }

        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'site_type' => $type,
            'template_namespace' => $code,
            'db_name' => 'placeholder', // provision() 會立刻回填
            'provision_status' => 'pending',
        ]);
    }

    // 實際建站動作：建庫、跑 migration+seed、寫設定檔。失敗時把原因記在
    // site 上，不重新拋出例外，見 6.3「資源耗盡與失敗補償」第 4 點。
    public function provision(Site $site): bool
    {
        $site->update(['provision_status' => 'creating']);

        // 見 README 6.3：資料庫名固定為 {prefix}{code}，不用 site_id 編號，
        // 方便直接從資料庫名對照是哪個子站，前綴見 config/multisite.php。
        $dbName = config('multisite.site_db_prefix').$site->code;
        $site->update(['db_name' => $dbName]);

        try {
            $this->createDatabase($dbName);
            $this->runSiteMigrationsAndSeed($dbName);
            $this->writeSiteConfigFile($site, $dbName);
            $this->updateSitesIndex($site);

            $site->update([
                'provision_status' => 'ready',
                'last_migrated_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $site->update([
                'provision_status' => 'failed',
                'provision_failed_reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // 設定/清除子站的主要網域，見 README 6.4：SiteResolver 判斷進站站點時
    // 網域優先於路徑前綴，但先前只有建站流程會寫入 sites.json 的 domains
    // 陣列（固定寫空陣列），完全沒有任何管理介面能設定實際網域，這支補上
    // 這個缺口。只允許對已經建置完成（provision_status=ready）的站點設定，
    // 建置中/失敗的站點連資料庫都還沒有，設網域也無法真的進站。
    /**
     * @throws InvalidArgumentException 驗證失敗（格式錯誤/被其他站點占用/站點尚未就緒）
     */
    public function updateDomain(Site $site, ?string $domain): Site
    {
        if ($site->provision_status !== 'ready') {
            throw new InvalidArgumentException(__('hq.provisioning.site_not_ready_for_domain'));
        }

        if ($domain !== null) {
            // 基本網域格式：英數、點、連字號組成的一般 hostname，不含協定/路徑。
            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
                throw new InvalidArgumentException(__('hq.provisioning.invalid_domain_format'));
            }

            if (Site::query()->where('primary_domain', $domain)->where('site_id', '!=', $site->site_id)->exists()) {
                throw new InvalidArgumentException(__('hq.provisioning.domain_taken', ['domain' => $domain]));
            }
        }

        $site->update(['primary_domain' => $domain]);
        $this->syncDomainToFiles($site);

        return $site;
    }

    // 見 updateDomain 開頭說明：sites.json（SiteResolver 實際查詢的來源）
    // 與各站設定檔（storage/app/sites/{site_id}.php，見 9 節目錄樹）都要
    // 同步更新，兩份都宣告了 domains 欄位，不能只改一邊留下不一致的資料。
    private function syncDomainToFiles(Site $site): void
    {
        $domains = $site->primary_domain ? [$site->primary_domain] : [];

        $indexPath = storage_path('app/sites/sites.json');
        $index = File::exists($indexPath) ? json_decode(File::get($indexPath), true) : [];
        $index[$site->site_id] = [
            'code' => $site->code,
            'domains' => $domains,
        ];
        File::put($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $configPath = storage_path("app/sites/{$site->site_id}.php");

        if (File::exists($configPath)) {
            $config = require $configPath;
            $config['domains'] = $domains;
            File::put($configPath, "<?php\n\nreturn ".var_export($config, true).";\n");
        }
    }

    private function createDatabase(string $dbName): void
    {
        DB::connection('mysql_provision')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function runSiteMigrationsAndSeed(string $dbName): void
    {
        Config::set('database.connections.site_current', SiteDatabaseConfig::connection($dbName));
        DB::purge('site_current');

        // 重要：Artisan 的 migrate/db:seed 在帶 --database= 時，會把
        // config('database.default') 永久改成該值，不會在指令結束後還原
        // （不像只影響單一指令，會一路留到這個 process 後續所有程式碼，
        // 長駐的 queue worker 尤其危險——上一個建站 Job 沒還原，下一個
        // Job 沒寫死連線的 Model 就會悄悄接到錯的資料庫）。這裡執行前後
        // 自己存回原本的預設值，不依賴 Artisan 的行為。
        $originalDefault = config('database.default');

        Artisan::call('migrate', [
            '--database' => 'site_current',
            '--path' => 'database/migrations',
            '--force' => true,
        ]);

        Artisan::call('db:seed', [
            '--database' => 'site_current',
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]);

        Config::set('database.default', $originalDefault);
    }

    private function writeSiteConfigFile(Site $site, string $dbName): void
    {
        $path = storage_path('app/sites');
        File::ensureDirectoryExists($path);

        // 資料庫連線資訊一律從 config 取（見 SiteDatabaseConfig 的說明），
        // 不能在執行期用 env()：config:cache 之後 env() 回傳 null，寫出來的
        // 站點設定檔會是空帳號／空資料庫，該子站之後所有請求都連不上。
        $credentials = SiteDatabaseConfig::connection($dbName);

        $config = [
            'site_id' => $site->site_id,
            'code' => $site->code,
            'site_type' => $site->site_type,
            'domains' => [],
            'path_prefix' => $site->code,
            'template_namespace' => $site->template_namespace,
            'vue_build_mode' => 'shared',
            'db' => [
                'host' => $credentials['host'],
                'port' => $credentials['port'],
                'database' => $credentials['database'],
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ],
        ];

        File::put(
            "{$path}/{$site->site_id}.php",
            "<?php\n\nreturn ".var_export($config, true).";\n"
        );
    }

    private function updateSitesIndex(Site $site): void
    {
        $indexPath = storage_path('app/sites/sites.json');
        $index = File::exists($indexPath) ? json_decode(File::get($indexPath), true) : [];
        $index[$site->site_id] = [
            'code' => $site->code,
            'domains' => [],
        ];
        File::put($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
