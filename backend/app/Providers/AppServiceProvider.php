<?php

/* *****************************************
File: AppServiceProvider.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 應用程式啟動註冊：Vite CSP nonce（見 README 4.2.1 第 3 點）、建站流程限流規則（見 6.3）。
***************************************** */

namespace App\Providers;

use App\Support\Database\Console\HqAwareMigrateCommand;
use App\Support\Database\Console\HqAwareResetCommand;
use App\Support\Database\Console\HqAwareRollbackCommand;
use App\Support\Database\Console\HqAwareStatusCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 防呆：多站模式下裸跑 migrate 系列指令自動改用主站 migration 路徑，
        // 見 App\Support\Database\Console\UsesHqMigrationPath。用 extend() 而非重新
        // singleton()：這幾個指令由延遲載入的 MigrationServiceProvider 註冊，直接
        // 覆寫綁定會在它載入時被蓋回去，extend() 則不受載入順序影響。
        $this->app->extend(MigrateCommand::class, fn ($c, $app) => new HqAwareMigrateCommand($app['migrator'], $app[Dispatcher::class]));
        $this->app->extend(RollbackCommand::class, fn ($c, $app) => new HqAwareRollbackCommand($app['migrator']));
        $this->app->extend(ResetCommand::class, fn ($c, $app) => new HqAwareResetCommand($app['migrator']));
        $this->app->extend(StatusCommand::class, fn ($c, $app) => new HqAwareStatusCommand($app['migrator']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 見 README 4.2.1 第 3 點：讓 @vite 產出的 <script>/<link> 自動帶上
        // 本次請求的 nonce，SecurityHeaders middleware 用 Vite::cspNonce() 取
        // 同一個值放進 CSP header。
        Vite::useCspNonce();

        // 見 README 6.3「資源耗盡與失敗補償」第 1 點：避免短時間內建立大量資料庫。
        RateLimiter::for('site-create', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // 見 README 6.8：單站部署包輸出也吃磁碟 I/O 與空間（整包複製共用程式碼），
        // 用比開站更嚴的限流，避免後台被連續點擊或迴圈呼叫灌爆。
        RateLimiter::for('site-package', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        // App 簽發 token 限流：按 IP 與 app_id 分別限制，見 docs/APP_DEVELOP.MD 8 節
        RateLimiter::for('factory-token', fn (Request $request) => [
            Limit::perMinute((int) config('factory.token_rate_per_ip'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('factory.token_rate_per_app'))->by('app:'.(string) $request->input('app_id')),
        ]);
    }
}
