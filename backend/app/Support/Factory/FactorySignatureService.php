<?php

/* *****************************************
File: FactorySignatureService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App 簽名校驗：狀態／有效期／IP／時間窗／HMAC／nonce 防重放，見 docs/APP_DEVELOP.MD 2 節
***************************************** */

namespace App\Support\Factory;

use App\Api\Factory\Models\FactoryApp;
use Illuminate\Support\Facades\Cache;

class FactorySignatureService
{
    /**
     * @return array{0: ?FactoryApp, 1: ?string} [App, 失敗原因]；成功時原因為 null。
     *         原因只寫日誌，不回給呼叫方。
     */
    public function verify(string $appId, int $timestamp, string $nonce, string $sign, ?string $ip): array
    {
        $app = FactoryApp::query()->where('app_id', $appId)->first();

        if (! $app) {
            return [null, 'app_not_found'];
        }
        if (! $app->status) {
            return [null, 'app_disabled'];
        }
        if (! $app->isUsable()) {
            return [null, 'app_expired'];
        }
        if (! $app->ipAllowed($ip)) {
            return [null, 'ip_not_allowed'];
        }
        if (abs(time() - $timestamp) > (int) config('factory.timestamp_window')) {
            return [null, 'timestamp_out_of_window'];
        }

        $expected = hash_hmac('sha256', "{$appId}|{$timestamp}|{$nonce}", $app->app_secret);

        if (! hash_equals($expected, strtolower($sign))) {
            return [null, 'signature_mismatch'];
        }

        // 簽名正確後才佔用 nonce（避免攻擊者亂填 nonce 消耗快取）；add 為原子操作。
        if (! Cache::add("factory_nonce:{$appId}:{$nonce}", 1, (int) config('factory.nonce_ttl'))) {
            return [null, 'nonce_replayed'];
        }

        return [$app, null];
    }
}
