<?php

/* *****************************************
File: FactorySyncScopesCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 掃描路由上的 factory.scope:xxx，把新出現的 scope 登記到 factory_scopes（只新增，不刪除），見 DEVELOP.MD 12.9
***************************************** */

namespace App\Console\Commands;

use App\Api\Factory\Models\FactoryScope;
use App\Console\Commands\Concerns\SelectsSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

// 後台授權界面直接列出 factory_scopes 供勾選，所以開發者新增 `factory.scope:xxx` 路由後，
// 只要跑這支指令，不需要手動維護權限表。
class FactorySyncScopesCommand extends Command
{
    use SelectsSite;

    protected $signature = 'factory:sync-scopes {--site= : 要同步到哪個子站，見 --site={site_id}}';

    protected $description = '掃描路由上的 factory.scope:xxx，同步到 factory_scopes（見 DEVELOP.MD 12.9）';

    public function handle(): int
    {
        if (! $this->resolveSiteOrFail() && \App\Support\Hq\HqPresence::available()) {
            return self::FAILURE;
        }

        $found = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'factory.scope:')) {
                    continue;
                }

                foreach (explode(',', substr($middleware, strlen('factory.scope:'))) as $code) {
                    $found[trim($code)] = true;
                }
            }
        }

        $added = 0;

        foreach (array_keys($found) as $code) {
            $scope = FactoryScope::query()->firstOrCreate(
                ['code' => $code],
                ['module' => str_contains($code, '.') ? strstr($code, '.', true) : $code],
            );

            if ($scope->wasRecentlyCreated) {
                $added++;
                $this->line("新增：{$code}");
            }
        }

        // 路由已不存在但表內仍有的 scope 只提示，不自動刪除（授權可能還引用著）
        $unused = FactoryScope::query()->whereNotIn('code', array_keys($found))->pluck('code');

        foreach ($unused as $code) {
            $this->warn("未使用（路由已不存在）：{$code}");
        }

        $this->info("掃描到 ".count($found)." 個 scope，新增 {$added} 個。");

        return self::SUCCESS;
    }
}
