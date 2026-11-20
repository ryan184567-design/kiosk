<?php

/* *****************************************
File: FactoryTestCase.php
Author: cliffdean
Date: 2026-11-20
Description: 廠商模式（App Token）測試的共用環境：暫存 sqlite、假站點、建立 App／簽名／預約單的輔助方法
***************************************** */

namespace Tests\Feature\Factory;

use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Models\FactoryAppScope;
use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Support\Site\SiteResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class FactoryTestCase extends TestCase
{
    protected const SECRET = 'test-secret-test-secret-test-secret-1234';

    protected string $dbFile;

    // 子站表在 site_current 連線，而 EnsureSiteContext 每次請求都會用 SiteResolver 的結果覆寫並 purge 它，
    // 記憶體 sqlite 會因 purge 消失。所以這裡用暫存檔案 sqlite，並把 SiteResolver 換成指向同一檔案的假實作。
    protected function setUp(): void
    {
        parent::setUp();

        $this->dbFile = tempnam(sys_get_temp_dir(), 'kiosk_test_');
        $sqlite = ['driver' => 'sqlite', 'database' => $this->dbFile, 'prefix' => '', 'foreign_key_constraints' => false];

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => $sqlite,
            // 模塊登記表（modules／site_modules）在主站連線 mysql，測試中一併指到同一個檔案
            'database.connections.mysql' => $sqlite,
            'database.connections.site_current' => $sqlite,
            'factory.jwt_secret' => self::SECRET,
            'multisite.enabled' => false,
        ]);
        DB::purge('sqlite');
        DB::purge('mysql');
        DB::purge('site_current');

        $this->app->bind(SiteResolver::class, fn () => new class extends SiteResolver
        {
            public function resolve(\Illuminate\Http\Request $request): array
            {
                return ['site_id' => 1, 'code' => 'default', 'site_type' => 'standard', 'template_namespace' => 'default', 'db' => config('database.connections.sqlite')];
            }
        });

        Artisan::call('migrate', [
            '--database' => 'sqlite', '--force' => true, '--realpath' => true,
            '--path' => array_map('base_path', [
                'database/hq/migrations/2024_01_01_000003_create_modules_table.php',
                'database/hq/migrations/2024_01_01_000004_create_site_modules_table.php',
                'database/migrations/2024_01_02_000001_create_portals_table.php',
                'database/migrations/2026_11_13_111916_create_doorplate_table.php',
                'database/migrations/2026_11_16_100331_add_company_name_to_doorplate_table.php',
                'database/migrations/2026_11_16_103348_create_guest_applies_table.php',
                'database/migrations/2026_11_16_103356_create_guests_table.php',
                'database/migrations/2026_11_19_092627_add_id_card_slot_no_to_guest_applies_table.php',
                'database/migrations/2026_11_19_154057_add_consent_form_to_guest_applies_table.php',
                'database/migrations/2026_11_19_170000_create_api_apps_table.php',
                'database/migrations/2026_11_19_170100_create_api_logs_table.php',
                'database/migrations/2026_11_20_090000_rename_api_app_tables_to_factory.php',
                'database/migrations/2026_11_20_100000_add_debug_mode_to_factory_apps_and_call_columns_to_factory_logs.php',
                'database/migrations/2026_11_20_110000_add_call_details_to_factory_logs.php',
            ]),
        ]);

        DB::table('portals')->insert(['portal_key' => 'admin', 'name' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        // module:guest 需要模塊已登記且對 site 1 開通（見 EnsureModuleEnabled）
        DB::table('modules')->insert(['code' => 'guest', 'name' => 'guest', 'is_core' => false, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_modules')->insert(['site_id' => 1, 'module_code' => 'guest', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('site_current');
        @unlink($this->dbFile);

        parent::tearDown();
    }

    // ── 工具 ──────────────────────────────────────────────

    /** 建立 App，回傳 [App, 明文 app_secret]。 */
    protected function makeApp(array $scopes = [], array $attrs = []): array
    {
        $key = 'k'.str_repeat('a', 47);
        $app = FactoryApp::query()->create(array_merge([
            'app_id' => 'app_test'.bin2hex(random_bytes(4)),
            'name' => 'test kiosk',
            'app_secret' => $key,
            'status' => true,
        ], $attrs));

        foreach ($scopes as $scope) {
            FactoryAppScope::query()->create(['factory_app_id' => $app->id, 'scope' => $scope]);
        }

        return [$app, $key];
    }

    protected function tokenPayload(string $appId, string $key, ?int $ts = null, ?string $nonce = null): array
    {
        $ts ??= time();
        $nonce ??= bin2hex(random_bytes(10));

        return [
            'app_id' => $appId, 'timestamp' => $ts, 'nonce' => $nonce,
            'sign' => hash_hmac('sha256', "{$appId}|{$ts}|{$nonce}", $key),
        ];
    }

    protected function bearer(FactoryApp $app, string $key): array
    {
        $res = $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $key));
        $res->assertOk();

        return ['Authorization' => 'Bearer '.$res->json('data.access_token')];
    }

    protected function makeApply(string $code = '12345678', int $status = GuestApply::STATUS_NOTIFIED, ?string $date = null): GuestApply
    {
        $apply = GuestApply::query()->create([
            'reservation_id' => 'GA'.random_int(10000000000, 99999999999),
            'reservation_type' => 0, 'doorplate_id' => 1, 'host_name' => '陳志明',
            'visit_date' => $date ?? today()->toDateString(),
            'visit_start_time' => '10:00:00', 'visit_end_time' => '11:00:00',
            'guest_count' => 1, 'consent_form' => 0, 'fast_pass_code' => $code, 'reservation_status' => $status,
        ]);
        // sqlite 沒有 date 型別，Eloquent 會寫成 'Y-m-d 00:00:00' 而比對不到；MySQL 無此問題，這裡改回純日期字串。
        DB::table('guest_applies')->where('id', $apply->id)->update(['visit_date' => $date ?? today()->toDateString()]);
        Guest::query()->create([
            'guest_applies_id' => $apply->id, 'name' => '王小明', 'company_name' => 'X', 'phone' => '0912345678',
        ]);

        return $apply;
    }
}
