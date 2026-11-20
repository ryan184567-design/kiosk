<?php

/* *****************************************
File: FactoryCallLogTest.php
Author: cliffdean
Date: 2026-11-20
Description: App 除錯模式與 API 呼叫記錄：記不記的規則、敏感欄位遮罩、截斷、500 擷取，以及依 App／模塊／方法／結果／時間搜尋
***************************************** */

namespace Tests\Feature\Factory;

use App\Api\Factory\Models\FactoryLog;
use App\Api\Factory\Services\FactoryAppService;
use App\Support\Factory\FactoryLogPruner;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class FactoryCallLogTest extends FactoryTestCase
{
    /** 業務接口的呼叫記錄（排除換 token 那一次：除錯模式的 App 取得 token 時也會被記錄） */
    private function calls()
    {
        return $this->allCalls()->where('path', 'not like', '%/factory/token');
    }

    private function allCalls()
    {
        return FactoryLog::query()->where('type', 'call');
    }

    // ── 非除錯模式：只記錄非 2xx ──────────────────────────────

    public function test_non_debug_app_does_not_log_successful_calls(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);
        $this->makeApply('12345678');

        $this->getJson('/api/factory/guest/applies?fast_pass_code=12345678', $this->bearer($app, $key))->assertOk();

        $this->assertSame(0, $this->calls()->count());
    }

    public function test_non_debug_app_logs_failed_calls_with_params_and_full_response(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=99999999', $this->bearer($app, $key))->assertStatus(404);

        $log = $this->calls()->firstOrFail();
        $this->assertSame($app->app_id, $log->app_id);
        $this->assertSame('guest', $log->module);
        $this->assertSame('FactoryGuestApplyController@index', $log->action);
        $this->assertSame('GET', $log->method);
        $this->assertSame('/api/factory/guest/applies', $log->path);
        $this->assertSame(404, $log->status_code);
        $this->assertFalse($log->success);
        $this->assertSame('non_2xx', $log->reason);
        $this->assertStringContainsString('99999999', $log->request_data);          // 傳入參數
        $this->assertStringContainsString('找不到此訪客預約單', $log->response_data); // 完整回應
        $this->assertStringContainsString('"error_code":40004', $log->response_data);
    }

    public function test_validation_failure_is_logged_for_non_debug_app(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);

        $this->getJson('/api/factory/guest/applies', $this->bearer($app, $key))->assertStatus(422);

        $this->assertSame(422, $this->calls()->firstOrFail()->status_code);
    }

    public function test_201_is_success_and_not_logged_for_non_debug_app(): void
    {
        Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
            ->post('/api/factory/_test/create', fn () => response()->json(['status' => true], 201));

        [$app, $key] = $this->makeApp(['test.record.write']);

        $this->postJson('/api/factory/_test/create', [], $this->bearer($app, $key))->assertStatus(201);

        $this->assertSame(0, $this->calls()->count());
    }

    public function test_missing_scope_403_is_logged_with_module_and_action(): void
    {
        [$app, $key] = $this->makeApp(['other.read']);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', $this->bearer($app, $key))->assertStatus(403);

        // 呼叫記錄（含參數與回應），以及原本的「權限被拒」記錄，兩者都能依模塊／方法搜到
        $this->assertSame(403, $this->calls()->firstOrFail()->status_code);
        $denied = FactoryLog::query()->where('type', 'scope_denied')->firstOrFail();
        $this->assertSame('guest', $denied->module);
        $this->assertSame('FactoryGuestApplyController@index', $denied->action);
    }

    // ── 除錯模式：成功失敗都記 ─────────────────────────────────

    public function test_debug_app_logs_successful_calls_too(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read'], ['is_debug' => true]);
        $this->makeApply('12345678');

        $this->getJson('/api/factory/guest/applies?fast_pass_code=12345678', $this->bearer($app, $key))->assertOk();

        $log = $this->calls()->firstOrFail();
        $this->assertTrue($log->success);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('debug', $log->reason);
        $this->assertStringContainsString('12345678', $log->request_data);
        $this->assertStringContainsString('陳志明', $log->response_data);
    }

    public function test_debug_mode_is_per_app(): void
    {
        [$debugApp, $k1] = $this->makeApp(['guest.apply.read'], ['is_debug' => true]);
        [$normalApp, $k2] = $this->makeApp(['guest.apply.read']);
        $this->makeApply('12345678');

        $this->getJson('/api/factory/guest/applies?fast_pass_code=12345678', $this->bearer($debugApp, $k1))->assertOk();
        $this->getJson('/api/factory/guest/applies?fast_pass_code=12345678', $this->bearer($normalApp, $k2))->assertOk();

        $this->assertSame([$debugApp->app_id], $this->calls()->pluck('app_id')->all());
    }

    // ── 敏感欄位遮罩、截斷、500 ───────────────────────────────

    public function test_sensitive_fields_are_masked_in_request_and_response(): void
    {
        Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
            ->post('/api/factory/_test/echo', fn (Request $r) => response()->json([
                'status' => true, 'data' => ['access_token' => 'LEAKED-TOKEN-VALUE', 'note' => 'visible'],
            ]));

        [$app, $key] = $this->makeApp(['test.record.write'], ['is_debug' => true]);
        $headers = $this->bearer($app, $key);

        $this->postJson('/api/factory/_test/echo', ['name' => 'plain-name', 'password' => 'hunter2', 'nested' => ['api_key' => 'K-123']], $headers)->assertOk();

        $log = $this->calls()->firstOrFail();
        $this->assertStringContainsString('plain-name', $log->request_data);
        $this->assertStringNotContainsString('hunter2', $log->request_data);
        $this->assertStringNotContainsString('K-123', $log->request_data);
        $this->assertStringContainsString('***', $log->request_data);
        $this->assertStringContainsString('visible', $log->response_data);
        $this->assertStringNotContainsString('LEAKED-TOKEN-VALUE', $log->response_data);

        // Authorization header（bearer token）不可被存下來
        $bearerToken = substr($headers['Authorization'], 7);
        $this->assertStringNotContainsString($bearerToken, (string) $log->request_data);
    }

    public function test_long_blocks_are_truncated_but_request_data_stays_valid_json(): void
    {
        config(['factory.log_max_bytes' => 300]);

        Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
            ->post('/api/factory/_test/big', fn () => response()->json(['status' => true, 'data' => str_repeat('資料', 2000)]));

        [$app, $key] = $this->makeApp(['test.record.write'], ['is_debug' => true]);

        $this->postJson('/api/factory/_test/big', ['blob' => str_repeat('x', 5000)], $this->bearer($app, $key))->assertOk();

        $log = $this->calls()->firstOrFail();

        // 請求：過大的區塊被「截斷標註物件」取代，整體仍是合法 JSON，後台才解析得出來
        $request = json_decode($log->request_data, true);
        $this->assertIsArray($request);
        $this->assertTrue($request['json']['_truncated']);
        $this->assertGreaterThan(5000, $request['json']['_original_bytes']);
        $this->assertLessThanOrEqual(300, strlen($request['json']['_preview']));

        // 回應內容：結尾標註已截斷、不切壞多位元組字元；回應大小記的是截斷前
        $this->assertStringContainsString('已截斷', $log->response_data);
        $this->assertLessThan(420, strlen($log->response_data));
        $this->assertTrue(mb_check_encoding($log->response_data, 'UTF-8'));
        $this->assertGreaterThan(300, $log->response_size);
    }

    public function test_server_error_500_is_captured(): void
    {
        Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
            ->get('/api/factory/_test/boom', fn () => throw new \RuntimeException('boom'));

        [$app, $key] = $this->makeApp(['test.record.write']);

        $this->getJson('/api/factory/_test/boom?x=1', $this->bearer($app, $key))->assertStatus(500);

        $log = $this->calls()->firstOrFail();
        $this->assertSame(500, $log->status_code);
        $this->assertStringContainsString('x', $log->request_data);
    }

    // ── 認不出是哪個 App 就不記；token 端點 ────────────────────

    public function test_unidentifiable_calls_are_not_logged(): void
    {
        $this->getJson('/api/factory/guest/applies?fast_pass_code=1')->assertStatus(401);
        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', ['Authorization' => 'Bearer garbage'])->assertStatus(401);

        $this->assertSame(0, $this->calls()->count());
    }

    public function test_disabled_app_401_is_logged_under_that_app(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);
        $headers = $this->bearer($app, $key);
        $app->update(['status' => false]);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', $headers)->assertStatus(401);

        $this->assertSame($app->app_id, $this->calls()->firstOrFail()->app_id);
    }

    public function test_token_endpoint_logging_and_access_token_masking(): void
    {
        [$app, $key] = $this->makeApp([], ['is_debug' => true]);
        $res = $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $key))->assertOk();

        $log = $this->allCalls()->firstOrFail();
        $this->assertSame('factory', $log->module);
        $this->assertSame('FactoryAuthController@token', $log->action);
        $this->assertSame('POST', $log->method);
        $this->assertStringNotContainsString($res->json('data.access_token'), $log->response_data); // 不存可用的 token
        $this->assertStringContainsString('***', $log->response_data);
        $this->assertStringContainsString('"token_type":"Bearer"', $log->response_data); // 名稱含 token 但不是機密，不遮罩
    }

    public function test_failed_token_call_is_logged_only_for_known_app(): void
    {
        [$app] = $this->makeApp();

        $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, 'wrong-secret'))->assertStatus(401);
        $this->postJson('/api/factory/token', $this->tokenPayload('app_does_not_exist', 'x'))->assertStatus(401);

        $this->assertSame([$app->app_id], $this->allCalls()->pluck('app_id')->all());
        $this->assertSame(401, $this->allCalls()->firstOrFail()->status_code);
    }

    // ── 記錄要盡可能完整：IP、時間、接口、header、GET／POST／JSON…、狀態碼、完整回應 ──

    public function test_call_log_captures_complete_request_and_response_information(): void
    {
        Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
            ->post('/api/factory/_test/full/{id}', fn () => response()->json(['status' => true, 'data' => ['ok' => 1]], 201)
                ->header('X-Custom-Response', 'resp-value'));

        [$app, $key] = $this->makeApp(['test.record.write'], ['is_debug' => true]);
        $headers = $this->bearer($app, $key) + ['X-Custom-Header' => 'hello', 'X-Api-Key' => 'k-secret', 'Referer' => 'http://ref.example/page'];

        $res = $this->postJson('/api/factory/_test/full/42?page=2&q=abc&token=url-secret', ['name' => 'n', 'password' => 'p', 'nested' => ['a' => 1]], $headers);
        $res->assertStatus(201);

        $log = $this->calls()->firstOrFail();

        // 索引欄位：IP、接口、狀態碼、時間
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('POST', $log->method);
        $this->assertSame('/api/factory/_test/full/42', $log->path);
        $this->assertSame('/api/factory/_test/full/{id}', $log->route);
        $this->assertSame('test.record.write', $log->scope);                 // 這支接口要求的 scope
        $this->assertSame(201, $log->status_code);
        $this->assertNotNull($log->started_at);                              // 請求起始時間
        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->duration_ms);                             // 耗時
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);

        // 請求細節
        $req = json_decode($log->request_data, true);
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/api/factory/_test/full/42', $req['url']);
        $this->assertStringContainsString('page=2', $req['url']);
        $this->assertSame('127.0.0.1', $req['client']['ip']);
        $this->assertArrayHasKey('host', $req['client']);
        $this->assertSame('http://ref.example/page', $req['client']['referer']);
        $this->assertSame('42', $req['route_params']['id']);                 // 路由參數
        $this->assertSame('2', $req['query']['page']);                       // GET 參數
        $this->assertSame('abc', $req['query']['q']);
        $this->assertSame('***', $req['query']['token']);                    // GET 裡的敏感值也遮罩
        $this->assertStringNotContainsString('url-secret', $log->request_data);
        $this->assertSame('n', $req['json']['name']);                        // JSON body
        $this->assertSame(1, $req['json']['nested']['a']);
        $this->assertSame('***', $req['json']['password']);
        $this->assertSame('hello', $req['headers']['x-custom-header']);      // 所有 header
        $this->assertSame('***', $req['headers']['authorization']);
        $this->assertSame('***', $req['headers']['x-api-key']);

        // 回應細節
        $this->assertSame('resp-value', json_decode($log->response_headers, true)['x-custom-response']);
        $this->assertStringContainsString('"ok":1', $log->response_data);
        $this->assertSame(strlen($res->getContent()), $log->response_size);
    }

    public function test_call_log_captures_form_fields_files_and_raw_body(): void
    {
        foreach (['form', 'file', 'raw'] as $name) {
            Route::middleware(['factory.auth', 'factory.scope:test.record.write'])
                ->post("/api/factory/_test/{$name}", fn () => response()->json(['status' => true]));
        }

        [$app, $key] = $this->makeApp(['test.record.write'], ['is_debug' => true]);
        $auth = ['HTTP_AUTHORIZATION' => $this->bearer($app, $key)['Authorization'], 'HTTP_ACCEPT' => 'application/json'];

        // POST 表單
        $this->call('POST', '/api/factory/_test/form', ['a' => '1', 'token' => 't-secret'], [], [], $auth)->assertOk();
        $form = json_decode($this->calls()->where('path', '/api/factory/_test/form')->firstOrFail()->request_data, true);
        $this->assertSame('1', $form['form']['a']);
        $this->assertSame('***', $form['form']['token']);
        $this->assertSame([], $form['json']);

        // 上傳檔案：只記檔名／大小／類型，不記內容
        $file = UploadedFile::fake()->create('report.pdf', 12, 'application/pdf');
        $this->call('POST', '/api/factory/_test/file', [], [], ['doc' => $file], $auth)->assertOk();
        $log = $this->calls()->where('path', '/api/factory/_test/file')->firstOrFail();
        $files = json_decode($log->request_data, true)['files'];
        $this->assertSame('report.pdf', $files['doc']['name']);
        $this->assertSame(12 * 1024, $files['doc']['size']);
        $this->assertSame('application/pdf', $files['doc']['mime']);

        // 非 JSON／非 form 的 body（XML、純文字…）存原始內容
        $this->call('POST', '/api/factory/_test/raw', [], [], [], $auth + ['CONTENT_TYPE' => 'text/xml'], '<order><id>7</id></order>')->assertOk();
        $raw = json_decode($this->calls()->where('path', '/api/factory/_test/raw')->firstOrFail()->request_data, true);
        $this->assertSame('<order><id>7</id></order>', $raw['raw_body']);
    }

    public function test_started_at_uses_app_timezone_consistent_with_created_at(): void
    {
        // 測試環境預設 UTC 看不出時區錯誤，所以改成 UTC+8 再驗：started_at 與 created_at 必須是同一個時區（相差 < 1 分鐘）
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Taipei');
        config(['app.timezone' => 'Asia/Taipei']);

        try {
            [$app, $key] = $this->makeApp(['guest.apply.read'], ['is_debug' => true]);
            $this->getJson('/api/factory/guest/applies?fast_pass_code=00000000', $this->bearer($app, $key))->assertStatus(404);

            $log = $this->calls()->firstOrFail();
            $this->assertEqualsWithDelta($log->created_at->timestamp, $log->started_at->timestamp, 60);
        } finally {
            date_default_timezone_set($original);
            config(['app.timezone' => $original]);
        }
    }

    public function test_failed_call_also_records_timing_and_response_headers(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=00000000', $this->bearer($app, $key))->assertStatus(404);

        $log = $this->calls()->firstOrFail();
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->duration_ms);
        $this->assertSame('/api/factory/guest/applies', $log->route);
        $this->assertSame('guest.apply.read', $log->scope);
        $this->assertArrayHasKey('content-type', json_decode($log->response_headers, true));
    }

    // ── 保留 30 天後移除 ──────────────────────────────────────

    public function test_logs_older_than_retention_are_removed_for_all_log_types(): void
    {
        $make = fn (string $type, int $daysAgo) => FactoryLog::query()->create([
            'app_id' => 'app_a', 'type' => $type, 'success' => true, 'created_at' => now()->subDays($daysAgo)->subMinutes(5),
        ]);

        $make('call', 31);
        $make('token', 35);
        $make('scope_denied', 40);
        $make('admin', 90);
        $make('call', 29);
        $make('token', 0);

        $pruner = app(FactoryLogPruner::class);

        $this->assertSame(4, $pruner->prune(30, dryRun: true));   // 預覽：不刪
        $this->assertSame(6, FactoryLog::query()->count());

        $this->assertSame(4, $pruner->prune(30));                 // 超過 30 天的 4 筆（所有類型）被移除
        $this->assertSame(2, FactoryLog::query()->count());
        $this->assertSame(0, FactoryLog::query()->where('created_at', '<', now()->subDays(30))->count());
    }

    public function test_retention_defaults_to_30_days_and_rejects_invalid_days(): void
    {
        $this->assertSame(30, config('factory.log_retention_days'));

        FactoryLog::query()->create(['app_id' => 'a', 'type' => 'call', 'success' => true, 'created_at' => now()->subDays(31)]);
        $this->assertSame(1, app(FactoryLogPruner::class)->prune());   // 不指定天數＝取設定值 30

        $this->expectException(\InvalidArgumentException::class);
        app(FactoryLogPruner::class)->prune(0);
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $this->assertTrue((bool) config('factory.log_prune_enabled'));

        Artisan::call('schedule:list');

        $this->assertStringContainsString('factory:logs-prune', Artisan::output());
    }

    // ── 後台：除錯模式開關與記錄搜尋 ───────────────────────────

    public function test_debug_flag_can_be_set_on_create_and_changed_on_update(): void
    {
        $service = app(FactoryAppService::class);

        [$app] = $service->create('debug-app', null, null, null, [], 'tester', true);
        $this->assertTrue($app->fresh()->is_debug);

        [$plain] = $service->create('plain-app', null, null, null, [], 'tester');
        $this->assertFalse($plain->fresh()->is_debug);

        $service->update($app->id, ['is_debug' => false], 'tester');
        $this->assertFalse($app->fresh()->is_debug);

        // 切換除錯模式會留下後台操作記錄
        $this->assertTrue(FactoryLog::query()->where('type', 'admin')->where('app_id', $app->app_id)->where('scope', 'like', '%is_debug=0%')->exists());
    }

    public function test_logs_can_be_searched_by_app_module_action_result_and_time(): void
    {
        $rows = [
            // app, module, action, success, created_at
            ['app_a', 'guest', 'FactoryGuestApplyController@index', true, '2026-11-10 09:00:00'],
            ['app_a', 'guest', 'FactoryGuestApplyController@index', false, '2026-11-11 10:00:00'],
            ['app_a', 'guest', 'FactoryGuestApplyController@checkIn', false, '2026-11-12 11:00:00'],
            ['app_b', 'guest', 'FactoryGuestApplyController@index', false, '2026-11-12 12:00:00'],
            ['app_b', 'parking', 'FactoryParkingController@enter', true, '2026-11-13 13:00:00'],
        ];

        foreach ($rows as [$appId, $module, $action, $ok, $at]) {
            FactoryLog::query()->create([
                'app_id' => $appId, 'type' => 'call', 'success' => $ok, 'module' => $module, 'action' => $action,
                'method' => 'GET', 'path' => '/x', 'status_code' => $ok ? 200 : 404, 'created_at' => $at,
            ]);
        }

        $service = app(FactoryAppService::class);
        $count = fn (array $f) => $service->logs($f)->count();

        $this->assertSame(5, $count([]));
        $this->assertSame(3, $count(['app_id' => 'app_a']));                                   // App ID
        $this->assertSame(1, $count(['module' => 'parking']));                                 // 業務模塊
        $this->assertSame(4, $count(['module' => 'guest']));
        $this->assertSame(3, $count(['action' => 'FactoryGuestApplyController@index']));       // 具體方法（完整）
        $this->assertSame(1, $count(['action' => 'checkIn']));                                 // 具體方法（片段）
        $this->assertSame(3, $count(['success' => false, 'module' => 'guest']));               // 結果
        $this->assertSame(2, $count(['success' => true]));
        $this->assertSame(3, $count(['date_from' => '2026-11-12', 'date_to' => '2026-11-13'])); // 時間（只給日期＝整天）
        $this->assertSame(1, $count(['date_from' => '2026-11-12 11:30:00', 'date_to' => '2026-11-12 12:30:00'])); // 時間（到秒）
        // 組合：某 App ＋ 某方法 ＋ 失敗 ＋ 時間區間
        $this->assertSame(1, $count(['app_id' => 'app_a', 'action' => 'index', 'success' => false, 'date_from' => '2026-11-11', 'date_to' => '2026-11-11']));
        // 搜尋字串中的 LIKE 萬用字元要被當成一般字元
        $this->assertSame(0, $count(['action' => '%']));
    }

    public function test_log_list_does_not_load_large_payload_columns(): void
    {
        FactoryLog::query()->create(['app_id' => 'app_a', 'type' => 'call', 'success' => false, 'request_data' => 'REQ', 'response_data' => 'RESP', 'created_at' => now()]);

        $service = app(FactoryAppService::class);
        $row = $service->logs([])->first();

        $this->assertArrayNotHasKey('request_data', $row->getAttributes());
        $this->assertArrayNotHasKey('response_data', $row->getAttributes());
        $this->assertSame('REQ', $service->logDetail($row->id)->request_data);
        $this->assertSame('RESP', $service->logDetail($row->id)->response_data);
    }
}
