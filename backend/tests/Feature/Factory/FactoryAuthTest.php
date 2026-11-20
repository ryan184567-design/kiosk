<?php

/* *****************************************
File: FactoryAuthTest.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App 機器認證（全局機制）、scope 授權與訪客機速通碼查詢的測試，也是新增「機器接口」時的測試範本。
***************************************** */

namespace Tests\Feature\Factory;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Models\FactoryAppScope;
use App\Support\Api\Doc\ApiAnnotationParser;
use App\Support\Api\Doc\ApiAnnotationValidator;
use App\Support\Factory\FactoryScopeMatcher;
use Firebase\JWT\JWT;
use ReflectionClass;
use App\Support\Site\SiteResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class FactoryAuthTest extends FactoryTestCase
{
    // ── 簽發 token ────────────────────────────────────────

    public function test_valid_signature_issues_token(): void
    {
        [$app, $key] = $this->makeApp();

        $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $key))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['access_token', 'expires_in']]);
    }

    public function test_bad_signature_unknown_app_and_disabled_app_all_get_same_401(): void
    {
        [$app, $key] = $this->makeApp();
        [$disabled, $dKey] = $this->makeApp([], ['status' => false]);

        $bad = $this->tokenPayload($app->app_id, 'wrong-key');
        $unknown = $this->tokenPayload('app_nope', $key);
        $off = $this->tokenPayload($disabled->app_id, $dKey);

        $messages = [];
        foreach ([$bad, $unknown, $off] as $payload) {
            $res = $this->postJson('/api/factory/token', $payload)->assertStatus(401);
            $messages[] = $res->json('error_message');
        }

        // 不透露是哪一步失敗
        $this->assertCount(1, array_unique($messages));
    }

    public function test_timestamp_out_of_window_is_rejected_and_returns_server_time(): void
    {
        [$app, $key] = $this->makeApp();

        $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $key, time() - 3600))
            ->assertStatus(401)
            ->assertJsonStructure(['server_time']);
    }

    public function test_nonce_cannot_be_replayed(): void
    {
        [$app, $key] = $this->makeApp();
        $payload = $this->tokenPayload($app->app_id, $key);

        $this->postJson('/api/factory/token', $payload)->assertOk();
        $this->postJson('/api/factory/token', $payload)->assertStatus(401);
    }

    public function test_ip_whitelist_is_enforced(): void
    {
        [$app, $key] = $this->makeApp([], ['allowed_ips' => ['10.9.9.0/24']]);

        // 測試請求來源為 127.0.0.1，不在白名單
        $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $key))->assertStatus(401);
    }

    public function test_reset_key_invalidates_old_key(): void
    {
        [$app, $oldKey] = $this->makeApp();
        $app->update(['app_secret' => 'new'.str_repeat('b', 45)]);

        $this->postJson('/api/factory/token', $this->tokenPayload($app->app_id, $oldKey))->assertStatus(401);
    }

    // ── App Token 只能訪問 /api/factory/*（DEVELOP.MD 12.8）──────────

    public function test_app_token_is_forbidden_on_user_routes(): void
    {
        [$app, $key] = $this->makeApp(['*']);

        // 有效的 app token 拿去呼叫一般（用戶 jwt.auth）接口：403，不是 401
        $this->getJson('/api/guest/applies', $this->bearer($app, $key))->assertStatus(403);
        $this->getJson('/api/system/factory/apps', $this->bearer($app, $key))->assertStatus(403);
    }

    public function test_user_route_with_garbage_token_is_still_401(): void
    {
        $this->getJson('/api/guest/applies', ['Authorization' => 'Bearer garbage'])->assertStatus(401);
    }

    // ── factory.auth ─────────────────────────────────────────

    public function test_protected_route_requires_token(): void
    {
        $this->getJson('/api/factory/guest/applies?fast_pass_code=1')->assertStatus(401);
        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', ['Authorization' => 'Bearer garbage'])->assertStatus(401);
    }

    public function test_jwt_algorithm_is_pinned_to_hs256(): void
    {
        [$app] = $this->makeApp(['guest.apply.read']);

        // 同密鑰但 HS512 簽的 token 必須被拒（防 alg 混淆）
        $token = JWT::encode(
            ['iss' => config('factory.jwt_issuer'), 'sub' => $app->app_id, 'iat' => time(), 'exp' => time() + 60],
            self::SECRET, 'HS512'
        );

        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_disabling_app_takes_effect_immediately(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);
        $headers = $this->bearer($app, $key);

        $app->update(['status' => false]);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', $headers)->assertStatus(401);
    }

    // ── factory.scope ────────────────────────────────────────

    public function test_missing_scope_gets_403(): void
    {
        [$app, $key] = $this->makeApp(['other.read']);

        $this->getJson('/api/factory/guest/applies?fast_pass_code=1', $this->bearer($app, $key))->assertStatus(403);
    }

    public function test_scope_matching_exact_module_wildcard_and_all(): void
    {
        $m = new FactoryScopeMatcher;

        $this->assertTrue($m->allows(['guest.apply.read'], 'guest.apply.read'));
        $this->assertTrue($m->allows(['guest.*'], 'guest.apply.read'));
        $this->assertTrue($m->allows(['*'], 'anything.at.all'));
        $this->assertFalse($m->allows(['guest.apply.write'], 'guest.apply.read'));
        $this->assertFalse($m->allows(['guests.*'], 'guest.apply.read'));
        $this->assertFalse($m->allows([], 'guest.apply.read'));
    }

    // ── 速通碼查詢 ────────────────────────────────────────

    public function test_query_by_fast_pass_code_returns_only_kiosk_fields(): void
    {
        [$app, $key] = $this->makeApp(['guest.*']);
        $this->makeApply('12345678');

        $res = $this->getJson('/api/factory/guest/applies?fast_pass_code=12345678', $this->bearer($app, $key))
            ->assertOk()
            ->assertJsonPath('data.host_name', '陳志明')
            ->assertJsonPath('data.visitor.0.guest_name', '王小明');

        // 不可外洩速通碼、QR code、門禁卡號等
        $this->assertArrayNotHasKey('fast_pass_code', $res->json('data'));
        $this->assertArrayNotHasKey('qr_code', $res->json('data'));
    }

    // ── 寫入類方法（報到、離場、存放證件位置…）同樣受支援，沒有「只讀」限制 ──

    public function test_write_methods_are_supported_and_scoped_per_action(): void
    {
        // 臨時路由：PUT／POST／DELETE 都走完整的 factory.auth ＋ factory.scope，一個動作一個 scope
        Route::middleware(['factory.auth', 'factory.scope:test.record.check_in'])
            ->put('/api/factory/_test/check-in', fn () => response()->json(['status' => true, 'data' => ['done' => 'check_in']]));
        Route::middleware(['factory.auth', 'factory.scope:test.record.check_out'])
            ->post('/api/factory/_test/check-out', fn () => response()->json(['status' => true, 'data' => ['done' => 'check_out']]));

        // 只授權「報到」：報到（PUT）可寫入，離場（POST）因沒有該 scope 被拒
        [$app, $key] = $this->makeApp(['test.record.check_in']);
        $headers = $this->bearer($app, $key);

        $this->putJson('/api/factory/_test/check-in', [], $headers)->assertOk()->assertJsonPath('data.done', 'check_in');
        $this->postJson('/api/factory/_test/check-out', [], $headers)->assertStatus(403);

        // 同時授權兩個動作則都可寫入
        [$app2, $key2] = $this->makeApp(['test.record.check_in', 'test.record.check_out']);
        $this->postJson('/api/factory/_test/check-out', [], $this->bearer($app2, $key2))->assertOk();
    }

    public function test_query_returns_404_when_not_found_or_not_eligible(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);
        $headers = $this->bearer($app, $key);

        $this->makeApply('11111111', GuestApply::STATUS_CANCELLED);
        $this->makeApply('22222222', GuestApply::STATUS_NOTIFIED, today()->subDay()->toDateString());

        foreach (['99999999', '11111111', '22222222'] as $code) {
            $this->getJson("/api/factory/guest/applies?fast_pass_code={$code}", $headers)->assertStatus(404);
        }
    }

    public function test_query_validates_input(): void
    {
        [$app, $key] = $this->makeApp(['guest.apply.read']);

        $this->getJson('/api/factory/guest/applies', $this->bearer($app, $key))->assertStatus(422);
    }

    // ── 命名與標註規範（README 5.8／DEVELOP.MD 12.9）─────────────

    /**
     * 掃描「所有模塊」的 Controller（新模塊自動納入），收集每個帶 @api-method 的方法：
     * [ 類別, 方法名, 標註陣列 ]。
     */
    private function allDocumentedControllerMethods(): array
    {
        $parser = app(ApiAnnotationParser::class);
        $rows = [];

        foreach (glob(app_path('Api/*/Controllers/*.php')) as $file) {
            $module = basename(dirname($file, 2));
            $class = 'App\\Api\\'.$module.'\\Controllers\\'.pathinfo($file, PATHINFO_FILENAME);

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $parsed = $method->class === $class ? $parser->parseMethod($method) : null;

                if ($parsed && ! empty($parsed['method']) && ! empty($parsed['path'])) {
                    $rows[] = [$class, $method->name, $parsed];
                }
            }
        }

        return $rows;
    }

    /**
     * 命名規則（定死）：路徑在 /api/factory/* 的 Controller 一律命名 Factory{Xxx}Controller，
     * 且只放 /api/factory/* 的接口；後台管理（/api/system/factory/*）與其他一般接口不可用 Factory 前綴。
     * 標 @api-auth 的接口一定在 /api/factory/*。
     */
    public function test_factory_controller_naming_convention(): void
    {
        $machine = 0;

        foreach ($this->allDocumentedControllerMethods() as [$class, $method, $parsed]) {
            $short = class_basename($class);
            $isFactoryPath = str_starts_with($parsed['path'], '/api/factory/');
            $isFactoryName = (bool) preg_match('/^Factory[A-Z]\w*Controller$/', $short);

            $this->assertSame($isFactoryPath, $isFactoryName,
                "{$class}::{$method}（{$parsed['path']}）：/api/factory/* 的接口必須放在 Factory{Xxx}Controller，"
                .'且 Factory 前綴只能用在 /api/factory/*（後台管理請用其他名稱，見 README 5.8）');

            if (isset($parsed['auth'])) {
                $this->assertTrue($isFactoryPath, "{$class}::{$method} 標了 @api-auth 就必須在 /api/factory/*");
            }

            if ($isFactoryPath) {
                $machine++;
            }
        }

        $this->assertGreaterThan(0, $machine);
    }

    /**
     * 所有機器接口（/api/factory/*）：標註格式必須通過驗證（與 swagger:generate／permission:sync-api 共用同一套驗證），
     * 並標 @api-auth；標 app 的必須有 @api-scope，且與路由上的 factory.scope:xxx 完全一致。
     */
    public function test_factory_annotations_are_valid_and_scopes_match_routes(): void
    {
        $validator = app(ApiAnnotationValidator::class);

        $routeScopes = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'factory.scope:')) {
                    $routeScopes[$route->uri()] = substr($m, strlen('factory.scope:'));
                }
            }
        }

        $app = 0;
        foreach ($this->allDocumentedControllerMethods() as [$class, $method, $parsed]) {
            // factory 模塊自己的後台管理接口（/api/system/factory/*）也要驗：permission:sync-api 與 swagger:generate 遇到錯誤標註會整批中斷
            $inFactoryModule = str_starts_with($class, 'App\\Api\\Factory\\');

            if (str_starts_with($parsed['path'], '/api/factory/') || $inFactoryModule) {
                $validator->validate($parsed, $parsed['method'], "{$class}::{$method}");
            }

            if (! str_starts_with($parsed['path'], '/api/factory/')) {
                continue;
            }
            $this->assertContains($parsed['auth'] ?? null, ['app', 'public'], "{$class}::{$method} 缺少 @api-auth（app 或 public）");

            if ($parsed['auth'] === 'app') {
                $app++;
                $this->assertSame(
                    $routeScopes[ltrim($parsed['path'], '/')] ?? null,
                    $parsed['scope'],
                    "{$class}::{$method} 的 @api-scope 與路由 factory.scope 不一致"
                );
            }
        }

        $this->assertGreaterThan(0, $app);
    }
}
