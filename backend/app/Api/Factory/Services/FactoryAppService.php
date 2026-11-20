<?php

/* *****************************************
File: FactoryAppService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 後台管理 App（機器）：建立、編輯、重置 app_secret、授權 scope、停用、刪除
***************************************** */

namespace App\Api\Factory\Services;

use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Models\FactoryAppScope;
use App\Api\Factory\Models\FactoryLog;
use App\Api\Factory\Models\FactoryScope;
use App\Support\Factory\FactoryScopeMatcher;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FactoryAppService
{
    public function apps(?string $keyword = null, ?bool $status = null): Builder
    {
        return FactoryApp::query()
            ->with('scopeRows:id,factory_app_id,scope')
            ->when($keyword, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('app_id', 'like', '%'.$keyword.'%')))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id');
    }

    public function find(int $id): FactoryApp
    {
        $app = FactoryApp::query()->with('scopeRows:id,factory_app_id,scope')->find($id);

        if (! $app) {
            throw new RuntimeException(__('api.factory.app_not_found'), 404);
        }

        return $app;
    }

    /**
     * 建立 App。回傳 [App, 明文 app_secret]：app_secret 只有此刻（與重置時）能看到，之後不可再取得。
     *
     * @return array{0: FactoryApp, 1: string}
     */
    public function create(string $name, ?array $allowedIps, ?string $expiredAt, ?string $remark, array $scopes, ?string $operator, bool $isDebug = false): array
    {
        $this->assertScopesValid($scopes);

        $appKey = Str::random(48);

        $app = DB::connection('site_current')->transaction(function () use ($name, $allowedIps, $expiredAt, $remark, $scopes, $appKey, $isDebug) {
            $app = FactoryApp::query()->create([
                'app_id' => 'app_'.Str::lower(Str::random(16)),
                'name' => $name,
                'app_secret' => $appKey,
                'status' => true,
                'is_debug' => $isDebug,
                'allowed_ips' => $allowedIps ?: null,
                'expired_at' => $expiredAt,
                'remark' => $remark,
            ]);

            $this->writeScopes($app, $scopes);

            return $app;
        });

        $this->audit('app_create', $app, $operator);

        return [$app, $appKey];
    }

    public function update(int $id, array $attributes, ?string $operator): FactoryApp
    {
        $app = $this->find($id);
        $app->update($attributes);

        $detail = collect(['status', 'is_debug'])
            ->filter(fn ($k) => array_key_exists($k, $attributes))
            ->map(fn ($k) => $k.'='.(int) $attributes[$k])
            ->implode(' ');

        $this->audit('app_update', $app, $operator, $detail !== '' ? $detail : null);

        return $app;
    }

    /** 重置 app_secret：舊 key 立即失效（簽名比對用的是資料庫中的新 key）。 */
    public function resetKey(int $id, ?string $operator): array
    {
        $app = $this->find($id);
        $appKey = Str::random(48);
        $app->update(['app_secret' => $appKey]);

        $this->audit('app_reset_key', $app, $operator);

        return [$app, $appKey];
    }

    /** 覆蓋式設定授權：傳入完整清單，不在清單內的授權會被移除。 */
    public function setScopes(int $id, array $scopes, ?string $operator): FactoryApp
    {
        $app = $this->find($id);
        $scopes = array_values(array_unique($scopes));
        $this->assertScopesValid($scopes);

        DB::connection('site_current')->transaction(fn () => $this->writeScopes($app, $scopes));

        $this->audit('app_set_scopes', $app, $operator, implode(',', $scopes));

        return $this->find($id);
    }

    /** 軟刪除；日誌以 app_id 字串關聯，不受影響。 */
    public function delete(int $id, ?string $operator): void
    {
        $app = $this->find($id);
        $app->delete();
        FactoryScopeMatcher::forget($app->id);

        $this->audit('app_delete', $app, $operator);
    }

    /** 授權界面用：已登記的 scope，依模塊分組。 */
    public function scopesGroupedByModule(): array
    {
        return FactoryScope::query()->orderBy('module')->orderBy('code')->get()
            ->groupBy('module')
            ->map(fn ($rows, $module) => [
                'module' => $module,
                'scopes' => $rows->map(fn (FactoryScope $s) => [
                    'code' => $s->code, 'name' => $s->name, 'description' => $s->description,
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * 記錄搜尋。可依 App ID、業務模塊、具體方法（Controller@method，模糊比對）、HTTP 方法、記錄類型、
     * 結果（成功／失敗）、狀態碼、時間區間（只給日期時涵蓋整天，也可給到秒）任意組合篩選。
     * 列表不撈 request_data／response_data（可能很大），要看請用 logDetail。
     *
     * @param  array{app_id?: ?string, module?: ?string, action?: ?string, method?: ?string, type?: ?string, success?: ?bool, status_code?: ?int, date_from?: ?string, date_to?: ?string}  $f
     */
    public function logs(array $f): Builder
    {
        return FactoryLog::query()
            ->select(['id', 'app_id', 'type', 'success', 'ip', 'scope', 'module', 'action', 'method', 'path', 'route', 'status_code', 'duration_ms', 'reason', 'started_at', 'created_at'])
            ->when($f['app_id'] ?? null, fn ($q, $v) => $q->where('app_id', $v))
            ->when($f['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', '%'.addcslashes($v, '%_\\').'%'))
            ->when($f['method'] ?? null, fn ($q, $v) => $q->where('method', strtoupper($v)))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(array_key_exists('success', $f) && $f['success'] !== null, fn ($q) => $q->where('success', $f['success']))
            ->when($f['status_code'] ?? null, fn ($q, $v) => $q->where('status_code', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', strlen($v) <= 10 ? $v.' 00:00:00' : $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', strlen($v) <= 10 ? $v.' 23:59:59' : $v))
            ->orderByDesc('id');
    }

    /** 單筆記錄完整內容（含傳入參數與完整回應）。 */
    public function logDetail(int $id): FactoryLog
    {
        $log = FactoryLog::query()->find($id);

        if (! $log) {
            throw new RuntimeException(__('api.factory.log_not_found'), 404);
        }

        return $log;
    }

    private function writeScopes(FactoryApp $app, array $scopes): void
    {
        FactoryAppScope::query()->where('factory_app_id', $app->id)->delete();

        foreach ($scopes as $scope) {
            FactoryAppScope::query()->create(['factory_app_id' => $app->id, 'scope' => $scope]);
        }

        FactoryScopeMatcher::forget($app->id);
    }

    /** 允許：`*`、`模塊.*`、`模塊.動作`（小寫英數、底線、點）。 */
    private function assertScopesValid(array $scopes): void
    {
        foreach ($scopes as $scope) {
            if ($scope !== '*' && ! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*(\.\*)?$/', (string) $scope)) {
                throw new RuntimeException(__('api.factory.scope_invalid', ['scope' => $scope]));
            }
        }
    }

    private function audit(string $action, FactoryApp $app, ?string $operator, ?string $detail = null): void
    {
        FactoryLog::record('admin', true, $app->app_id, null, $detail, trim($action.' by '.($operator ?? '-')));
    }
}
