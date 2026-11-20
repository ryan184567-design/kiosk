<?php

/* *****************************************
File: FactoryLog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 廠商模式（App Token）記錄：token 簽發、權限被拒、後台操作、API 呼叫（type = call）
***************************************** */

namespace App\Api\Factory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FactoryLog extends Model
{
    public $timestamps = false;

    protected $connection = 'site_current';

    protected $table = 'factory_logs';

    protected $fillable = [
        'app_id', 'type', 'success', 'ip', 'scope', 'module', 'action', 'method', 'path', 'route',
        'status_code', 'started_at', 'duration_ms', 'request_data', 'response_headers', 'response_data', 'response_size',
        'reason', 'created_at',
    ];

    protected $casts = ['success' => 'boolean', 'status_code' => 'integer', 'duration_ms' => 'integer', 'response_size' => 'integer',
        'started_at' => 'datetime', 'created_at' => 'datetime'];

    /**
     * @param  array<string, mixed>  $extra  module、action、method、path、status_code、request_data、response_data
     */
    public static function record(string $type, bool $success, ?string $appId, ?string $ip, ?string $scope = null, ?string $reason = null, array $extra = []): void
    {
        try {
            static::query()->create(array_merge([
                'app_id' => $appId, 'type' => $type, 'success' => $success, 'ip' => $ip,
                'scope' => $scope, 'reason' => $reason, 'created_at' => now(),
            ], $extra));
        } catch (\Throwable) {
            // 日誌寫入失敗不可影響主流程
        }
    }

    /**
     * 從目前請求的路由取出「業務模塊」與「具體方法」，供日誌搜尋用。
     * 路由指向 App\Api\{Module}\Controllers\{Controller}@{method}：
     *   module = 模塊代碼（snake_case，與 module.json 的 code 一致），action = Controller@method。
     *
     * @return array{module: ?string, action: ?string, method: string, path: string}
     */
    public static function routeInfo(Request $request): array
    {
        $module = $action = null;
        $name = (string) $request->route()?->getActionName();

        if (preg_match('/^App\\\\Api\\\\(\w+)\\\\Controllers\\\\(\w+)@(\w+)$/', $name, $m)) {
            $module = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1]));
            $action = $m[2].'@'.$m[3];
        }

        return ['module' => $module, 'action' => $action, 'method' => $request->method(), 'path' => '/'.ltrim($request->path(), '/')];
    }
}
