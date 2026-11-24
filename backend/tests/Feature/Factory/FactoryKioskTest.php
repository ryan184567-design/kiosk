<?php

/* *****************************************
File: FactoryKioskTest.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客機狀態回報接口測試：心跳、故障／恢復／系統正常、離線判定、故障上報儀錶板
***************************************** */

namespace Tests\Feature\Factory;

use App\Api\Guest\Models\DashboardReportSetting;
use App\Api\Guest\Models\GuestKioskStatus;
use App\Api\Guest\Services\DashboardReportService;
use App\Api\Guest\Services\GuestKioskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FactoryKioskTest extends FactoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--database' => 'sqlite', '--force' => true, '--realpath' => true,
            '--path' => array_map('base_path', [
                'database/migrations/2026_11_23_170000_create_guest_kiosk_tables.php',
                'database/migrations/2026_11_23_170100_create_dashboard_report_settings_table.php',
                'database/migrations/2026_11_24_100000_create_dashboard_report_logs_table.php',
            ]),
        ]);
    }

    private function kiosk(array $scopes = ['guest.*']): array
    {
        [$app, $key] = $this->makeApp($scopes);

        return [$app, $this->bearer($app, $key)];
    }

    public function test_requires_token_and_scope(): void
    {
        $this->postJson('/api/factory/guest/kiosk/heartbeat')->assertStatus(401);

        [$app, $key] = $this->makeApp(['guest.apply.read']);
        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $this->bearer($app, $key))->assertStatus(403);
        $this->postJson('/api/factory/guest/kiosk/fault', ['fault_code' => 'SYSTEM_OK'], $this->bearer($app, $key))->assertStatus(403);
    }

    public function test_heartbeat_records_last_report_and_log(): void
    {
        [$app, $headers] = $this->kiosk();

        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $headers)->assertOk()->assertJsonStructure(['data' => ['server_time']]);

        $status = GuestKioskStatus::query()->where('app_id', $app->app_id)->firstOrFail();
        $this->assertTrue($status->isOnline());
        $this->assertSame([], $status->faults);
        $this->assertSame(1, DB::table('guest_kiosk_logs')->where('app_id', $app->app_id)->where('log_type', 'heartbeat')->count());
    }

    public function test_heartbeat_faults_overwrite_and_omitted_faults_keep(): void
    {
        [$app, $headers] = $this->kiosk();

        $this->postJson('/api/factory/guest/kiosk/heartbeat', ['faults' => [['fault_code' => 'PRINTER_PAPER_OUT']]], $headers)->assertOk();
        $this->assertSame('PRINTER_PAPER_OUT', GuestKioskStatus::query()->first()->faults[0]['fault_code']);

        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $headers)->assertOk();
        $this->assertCount(1, GuestKioskStatus::query()->first()->faults);

        $this->postJson('/api/factory/guest/kiosk/heartbeat', ['faults' => []], $headers)->assertOk();
        $this->assertSame([], GuestKioskStatus::query()->first()->faults);
    }

    public function test_more_than_one_minute_without_report_is_offline(): void
    {
        [$app, $headers] = $this->kiosk();
        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $headers)->assertOk();

        $this->assertSame('online', app(GuestKioskService::class)->snapshot()[0]['status']);

        DB::table('guest_kiosk_statuses')->update(['last_reported_at' => now()->subSeconds(61)]);
        $this->assertSame('offline', app(GuestKioskService::class)->snapshot()[0]['status']);
    }

    public function test_fault_occurred_repeat_resolved_and_system_ok(): void
    {
        [$app, $headers] = $this->kiosk();
        $url = '/api/factory/guest/kiosk/fault';

        $this->postJson($url, ['fault_code' => 'PRINTER_PAPER_OUT'], $headers)
            ->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.faults.0.fault_code', 'PRINTER_PAPER_OUT');

        // 重複回報同一個故障：不算變動
        $this->postJson($url, ['fault_code' => 'PRINTER_PAPER_OUT'], $headers)->assertOk()->assertJsonPath('data.changed', false);

        $this->postJson($url, ['fault_code' => 'ID_SCANNER_DISCONNECTED'], $headers)->assertOk()->assertJsonCount(2, 'data.faults');
        $this->postJson($url, ['fault_code' => 'PRINTER_PAPER_OUT', 'fault_status' => 'resolved'], $headers)->assertOk()->assertJsonCount(1, 'data.faults');

        // 系統正常：清除全部故障；再報一次不算變動
        $this->postJson($url, ['fault_code' => 'SYSTEM_OK'], $headers)->assertOk()->assertJsonPath('data.changed', true)->assertJsonCount(0, 'data.faults');
        $this->postJson($url, ['fault_code' => 'SYSTEM_OK'], $headers)->assertOk()->assertJsonPath('data.changed', false);

        $this->assertSame(6, DB::table('guest_kiosk_logs')->where('log_type', 'fault')->count());
    }

    public function test_fault_validation(): void
    {
        [, $headers] = $this->kiosk();

        $this->postJson('/api/factory/guest/kiosk/fault', ['fault_code' => 'NOPE'], $headers)->assertStatus(422);
        $this->postJson('/api/factory/guest/kiosk/fault', [], $headers)->assertStatus(422);
        $this->postJson('/api/factory/guest/kiosk/fault', ['fault_code' => 'PRINTER_PAPER_OUT', 'fault_status' => 'bad'], $headers)->assertStatus(422);
    }

    public function test_fault_change_is_reported_to_dashboard_only_when_enabled_and_changed(): void
    {
        Http::fake(['dash.test/*' => Http::response(['ok' => true])]);
        [$app, $headers] = $this->kiosk();
        $url = '/api/factory/guest/kiosk/fault';

        // 未啟用：不上報
        $this->postJson($url, ['fault_code' => 'PRINTER_PAPER_OUT'], $headers)->assertOk();
        Http::assertNothingSent();

        app(DashboardReportService::class)->updateSettings([
            'is_enabled' => true, 'url' => 'https://dash.test/in', 'auth_items' => [['name' => 'X-API-Key', 'location' => 'header', 'bearer' => true, 'value' => 'secret-key-1234']],
        ]);

        // 已啟用但狀態沒變（重複回報）：不上報
        $this->postJson($url, ['fault_code' => 'PRINTER_PAPER_OUT'], $headers)->assertOk();
        Http::assertNothingSent();

        // 狀態有變：上報 device_fault，簽名放標頭並加 Bearer
        $this->postJson($url, ['fault_code' => 'PRINTER_DISCONNECTED'], $headers)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['notify_type'] === 'device_fault'
            && $r['kiosk_id'] === $app->app_id
            && $r['fault_code'] === 'PRINTER_DISCONNECTED'
            && $r['fault_status'] === 'occurred'
            && $r['fault_message'] === '熱敏出單機連接故障'
            && $r->hasHeader('X-API-Key', 'Bearer secret-key-1234'));

        // 上傳內容沒勾「心跳」：不上報
        app(DashboardReportService::class)->updateSettings(['contents' => ['approved']]);
        $this->postJson($url, ['fault_code' => 'SYSTEM_OK'], $headers)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_dashboard_failure_never_breaks_the_kiosk_response(): void
    {
        Http::fake(['dash.test/*' => Http::response('boom', 500)]);
        [, $headers] = $this->kiosk();
        app(DashboardReportService::class)->updateSettings(['is_enabled' => true, 'url' => 'https://dash.test/in']);

        $this->postJson('/api/factory/guest/kiosk/fault', ['fault_code' => 'PRINTER_PAPER_OUT'], $headers)->assertOk();
    }

    public function test_heartbeat_payload_lists_all_kiosks_with_status(): void
    {
        Http::fake(['dash.test/*' => Http::response(['ok' => true])]);
        [, $h1] = $this->kiosk();
        [, $h2] = $this->kiosk();
        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $h1)->assertOk();
        $this->postJson('/api/factory/guest/kiosk/heartbeat', [], $h2)->assertOk();
        DB::table('guest_kiosk_statuses')->orderBy('id')->limit(1)->update(['last_reported_at' => now()->subMinutes(5)]);

        $svc = app(DashboardReportService::class);
        $svc->updateSettings(['is_enabled' => true, 'url' => 'https://dash.test/in', 'auth_items' => [['name' => 'X-API-Key', 'location' => 'body', 'value' => 'abc']]]);

        $this->assertTrue($svc->reportHeartbeat());
        Http::assertSent(fn ($r) => $r['notify_type'] === 'heartbeat'
            && $r['nsst']['status'] === 'online'
            && collect($r['kiosks'])->pluck('status')->sort()->values()->all() === ['offline', 'online']
            && $r['X-API-Key'] === 'abc'
            && ! $r->hasHeader('X-API-Key'));
        $this->assertNotEmpty(DashboardReportSetting::query()->first()->contents);
    }

    // ── 儀錶板上報設定（直接呼叫 Controller，後台 jwt 登入流程不在這裡測）──────

    private function putSettings(array $body)
    {
        $request = \Illuminate\Http\Request::create('/api/guest/dashboard-settings', 'PUT', $body);

        return app(\App\Api\Guest\Controllers\GuestDashboardReportController::class)
            ->dashboardSettingUpdate($request, app(DashboardReportService::class))->getData(true);
    }

    public function test_settings_save_mask_keep_and_clear_auth_items(): void
    {
        $base = ['is_enabled' => true, 'url' => 'https://dash.test/in', 'contents' => ['heartbeat', 'approved', 'approved']];

        $res = $this->putSettings($base + ['auth_items' => [
            ['name' => 'X-API-Key', 'location' => 'header', 'bearer' => true, 'value' => 'j2Krhxfh-secret-9XyZ'],
            ['name' => 'site_code', 'location' => 'body', 'value' => 'NSST-01'],
        ]]);
        $this->assertSame(['heartbeat', 'approved'], $res['data']['contents']);
        $this->assertSame([
            ['name' => 'X-API-Key', 'location' => 'header', 'bearer' => true, 'value_masked' => '********9XyZ'],
            ['name' => 'site_code', 'location' => 'body', 'bearer' => false, 'value_masked' => '********T-01'],
        ], $res['data']['auth_items']);
        $this->assertStringNotContainsString('secret', json_encode($res));
        // 加密儲存，資料庫裡不是明文
        $raw = (string) DB::table('dashboard_report_settings')->value('auth_items');
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertStringNotContainsString('NSST', $raw);

        // 沒帶 auth_items：整組沿用
        $res = $this->putSettings(['is_enabled' => true, 'url' => 'https://dash.test/other', 'contents' => ['checkout']]);
        $this->assertCount(2, $res['data']['auth_items']);

        // 帶了但沒帶 value：沿用同名稱＋同位置的原值；順序與新增可調整
        $res = $this->putSettings($base + ['auth_items' => [
            ['name' => 'site_code', 'location' => 'body'],
            ['name' => 'x-api-key', 'location' => 'header', 'bearer' => false],
            ['name' => 'X-Other', 'location' => 'header', 'value' => 'new-0000'],
        ]]);
        $this->assertSame(['site_code', 'x-api-key', 'X-Other'], array_column($res['data']['auth_items'], 'name'));
        $this->assertSame('********9XyZ', $res['data']['auth_items'][1]['value_masked']);
        $this->assertFalse($res['data']['auth_items'][1]['bearer']);

        // 新增的組沒有值：驗證失敗
        try {
            $this->putSettings($base + ['auth_items' => [['name' => 'brand-new', 'location' => 'body']]]);
            $this->fail('新增的組沒有值應該要驗證失敗');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertTrue(true);
        }

        // 傳空陣列：全部清除
        $res = $this->putSettings($base + ['auth_items' => []]);
        $this->assertSame([], $res['data']['auth_items']);
    }

    public function test_multiple_auth_items_go_to_headers_and_body_without_overriding_the_message(): void
    {
        Http::fake(['dash.test/*' => Http::response(['ok' => true])]);
        $svc = app(DashboardReportService::class);
        $svc->updateSettings(['is_enabled' => true, 'url' => 'https://dash.test/in', 'auth_items' => [
            ['name' => 'X-API-Key', 'location' => 'header', 'value' => 'k1'],
            ['name' => 'Authorization', 'location' => 'header', 'bearer' => true, 'value' => 'tok'],
            ['name' => 'site_code', 'location' => 'body', 'value' => 'NSST-01'],
            ['name' => 'tenant', 'location' => 'body', 'value' => 'abc'],
        ]]);

        $result = $svc->testConnection();
        $this->assertTrue($result['success']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Key', 'k1')
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['site_code'] === 'NSST-01'
            && $r['tenant'] === 'abc'
            && $r['notify_type'] === 'heartbeat');
    }

    public function test_settings_validation(): void
    {
        $call = fn (array $body) => $this->putSettings($body);

        // 啟用時網址必填
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $call(['is_enabled' => true, 'contents' => ['heartbeat']]);
    }

    public function test_settings_reject_unknown_content_method_and_bad_header_name(): void
    {
        foreach ([
            ['contents' => ['bogus']],
            ['contents' => ['heartbeat'], 'upload_method' => 'file'],
            ['contents' => ['heartbeat'], 'format' => 'xml'],
            ['contents' => ['heartbeat'], 'schedule' => 'cron'],
            ['contents' => ['heartbeat'], 'auth_items' => [['name' => "X-Key
Evil: 1", 'location' => 'header', 'value' => 'v']]],
            ['contents' => ['heartbeat'], 'auth_items' => [['name' => 'a', 'location' => 'cookie', 'value' => 'v']]],
            ['contents' => ['heartbeat'], 'auth_items' => [['name' => 'a', 'location' => 'header']]],
            ['contents' => ['heartbeat'], 'auth_items' => [['name' => 'message_id', 'location' => 'body', 'value' => 'v']]],
            ['contents' => ['heartbeat'], 'auth_items' => [['name' => 'a', 'location' => 'body', 'value' => '1'], ['name' => 'A', 'location' => 'body', 'value' => '2']]],
            ['contents' => ['heartbeat'], 'url' => 'ftp://x.test/a'],
        ] as $bad) {
            try {
                $this->putSettings(['is_enabled' => false] + $bad);
                $this->fail('應該要驗證失敗：'.json_encode($bad));
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    // ── 重試：5 秒逾時、失敗 10 秒後重試、共 3 次失敗就丟棄 ──────────────────

    private function jobAtAttempt(int $attempt, string $url = 'https://retry.test/in'): \App\Jobs\SendDashboardNotification
    {
        $job = new \App\Jobs\SendDashboardNotification($url, ['X-API-Key' => 'k'], ['notify_type' => 'heartbeat', 'message_id' => 'm-1']);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $job->setJob($queueJob);

        return $job;
    }

    public function test_retry_policy_is_three_tries_ten_seconds_apart(): void
    {
        $job = $this->jobAtAttempt(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff);
        $this->assertSame(5, \App\Support\Dashboard\DashboardClient::TIMEOUT_SECONDS);
        // 簽名在佇列資料表裡，任務必須加密儲存
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
    }

    public function test_failed_attempts_one_and_two_throw_so_the_queue_retries(): void
    {
        Http::fake(['retry.test/*' => Http::response('boom', 500)]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->jobAtAttempt($attempt)->handle();
                $this->fail("第 {$attempt} 次失敗應該丟例外讓佇列重試");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_third_failure_is_discarded_without_throwing(): void
    {
        Http::fake(['retry.test/*' => Http::response('boom', 503)]);
        \Illuminate\Support\Facades\Log::spy();

        // 第 3 次還失敗：不再丟例外（任務結束、不進 failed_jobs），只記一筆 error
        $this->jobAtAttempt(3)->handle();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once();
    }

    public function test_timeout_counts_as_failure_and_success_stops_retrying(): void
    {
        Http::fake([
            'down.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timeout'),
            'ok.test/*' => Http::response(['ok' => true]),
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            $this->jobAtAttempt(1, 'https://ok.test/in')->handle();   // 成功：不丟例外
            $this->assertTrue(true);
        } finally {
            $this->jobAtAttempt(1, 'https://down.test/in')->handle(); // 逾時：視為失敗
        }
    }

    public function test_reporting_queues_one_job_with_the_message_and_signature(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $svc = app(DashboardReportService::class);
        $svc->updateSettings(['is_enabled' => true, 'url' => 'https://dash.test/in', 'auth_items' => [['name' => 'X-API-Key', 'location' => 'header', 'bearer' => true, 'value' => 'sig-1'], ['name' => 'site_code', 'location' => 'body', 'value' => 'NSST-01']]]);

        $this->assertTrue($svc->reportHeartbeat());

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendDashboardNotification::class, fn ($job) => $job->url === 'https://dash.test/in'
            && $job->headers === ['X-API-Key' => 'Bearer sig-1']
            && $job->payload['notify_type'] === 'heartbeat'
            && $job->payload['site_code'] === 'NSST-01'
            && ! empty($job->payload['message_id']));

        // 沒啟用就不排入佇列
        \Illuminate\Support\Facades\Queue::fake();
        $svc->updateSettings(['is_enabled' => false]);
        $this->assertFalse($svc->reportHeartbeat());
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_connection_test_returns_and_logs_the_dashboard_response(): void
    {
        Http::fake(['dash.test/*' => Http::response(['code' => 0, 'msg' => 'received'], 200)]);
        $svc = app(DashboardReportService::class);
        $svc->updateSettings(['url' => 'https://dash.test/in', 'auth_items' => [
            ['name' => 'X-API-Key', 'location' => 'header', 'value' => 'hdr-secret-1111'],
            ['name' => 'site_code', 'location' => 'body', 'value' => 'NSST-0001'],
        ]]);

        $result = $svc->testConnection();

        $this->assertTrue($result['success']);
        $this->assertSame('{"code":0,"msg":"received"}', $result['response_body']);

        $log = \App\Api\Guest\Models\DashboardReportLog::query()->firstOrFail();
        $this->assertTrue($log->is_test);
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('heartbeat', $log->notify_type);
        $this->assertSame('{"code":0,"msg":"received"}', $log->response_body);
        $this->assertSame('********0001', $log->request_body['site_code']);
        $this->assertStringNotContainsString('hdr-secret', json_encode($log->toArray()));

        // 失敗：記下狀態碼、錯誤與對方回的內容
        Http::fake(['bad.test/*' => Http::response('nope', 500)]);
        $bad = $svc->testConnection('https://bad.test/in');
        $this->assertFalse($bad['success']);
        $this->assertSame('nope', $bad['response_body']);
        $this->assertSame(1, \App\Api\Guest\Models\DashboardReportLog::query()->where('success', false)->where('status_code', 500)->count());
    }

    public function test_every_queue_attempt_is_logged_with_the_same_message_id(): void
    {
        Http::fake(['down.test/*' => Http::response('busy', 503)]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->jobAtAttempt($attempt, 'https://down.test/in')->handle();
            } catch (\RuntimeException) {
            }
        }
        $this->jobAtAttempt(3, 'https://down.test/in')->handle();

        $logs = \App\Api\Guest\Models\DashboardReportLog::query()->orderBy('id')->get();
        $this->assertSame([1, 2, 3], $logs->pluck('attempt')->all());
        $this->assertSame(['m-1'], $logs->pluck('message_id')->unique()->values()->all());
        $this->assertSame([false, false, false], $logs->pluck('is_test')->all());
        $this->assertSame('busy', $logs[0]->response_body);
        $this->assertSame('HTTP 503', $logs[0]->error);
    }

    public function test_logs_endpoint_lists_newest_first_with_filters(): void
    {
        $svc = app(DashboardReportService::class);
        $mk = fn (array $o) => \App\Api\Guest\Models\DashboardReportLog::query()->create($o + [
            'notify_type' => 'heartbeat', 'attempt' => 1, 'is_test' => false, 'url' => 'https://dash.test/in',
            'success' => true, 'status_code' => 200, 'duration_ms' => 5, 'request_body' => ['a' => 1],
        ]);
        $mk([]);
        $mk(['notify_type' => 'reservation_approved', 'success' => false, 'status_code' => 500, 'response_body' => 'x']);
        $mk(['is_test' => true]);

        $call = fn (array $q) => app(\App\Api\Guest\Controllers\GuestDashboardReportController::class)
            ->dashboardLogs(\Illuminate\Http\Request::create('/api/guest/dashboard-logs', 'GET', $q), $svc)->getData(true)['data'];

        $all = $call([]);
        $this->assertSame(3, $all['total']);
        $this->assertTrue($all['items'][0]['is_test']);   // 新的在前
        $this->assertSame('x', $call(['notify_type' => 'reservation_approved'])['items'][0]['response_body']);
        $this->assertSame(1, $call(['success' => 0])['total']);
        $this->assertSame(1, $call(['is_test' => 1])['total']);
        $this->assertSame(2, $call(['is_test' => 0])['total']);
        $this->assertSame(3, $call(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()])['total']);
    }

    public function test_apply_flows_report_to_dashboard(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        // 來訪事由字典表不在這個測試的資料表裡，換成固定標籤
        $this->mock(\App\Api\Guest\Services\GuestReasonDictionary::class, fn ($m) => $m->shouldReceive('label')->andReturn('洽公'));
        app(DashboardReportService::class)->updateSettings(['is_enabled' => true, 'url' => 'https://dash.test/in']);
        $svc = app(\App\Api\Guest\Services\GuestApplyService::class);
        $types = fn () => \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\SendDashboardNotification::class)
            ->map(fn ($j) => $j->payload['notify_type'])->values()->all();

        $a = $this->makeApply('11111111', \App\Api\Guest\Models\GuestApply::STATUS_PENDING);
        $svc->status($a->id, 1);                     // 0 -> 10
        $svc->status($a->id, 1);                     // 10 -> 10 不是審核動作，不重複上報
        $this->assertSame(['reservation_approved'], $types());

        $b = $this->makeApply('22222222', \App\Api\Guest\Models\GuestApply::STATUS_EDIT_PENDING);
        $svc->status($b->id, 1);                     // 1 -> 11
        $svc->cancel($b->id);
        $this->assertSame(['reservation_approved', 'reservation_modified', 'reservation_cancelled'], $types());
    }
}
