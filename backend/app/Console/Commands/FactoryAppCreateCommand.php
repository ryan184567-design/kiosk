<?php

/* *****************************************
File: FactoryAppCreateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 以指令建立 App（首次部署尚無後台帳號時使用；日常請用後台「廠商 App管理」）
***************************************** */

namespace App\Console\Commands;

use App\Api\Factory\Services\FactoryAppService;
use App\Console\Commands\Concerns\SelectsSite;
use App\Support\Hq\HqPresence;
use Illuminate\Console\Command;
use RuntimeException;

class FactoryAppCreateCommand extends Command
{
    use SelectsSite;

    protected $signature = 'factory:app-create
        {name : App 名稱，例如「自助訪客機 1 號」}
        {--scope=* : 授權的 scope，可重複，例如 --scope=guest.apply.read}
        {--debug : 開啟除錯模式（每次呼叫都記錄傳入參數與完整回應；預設只記錄非 2xx）}
        {--site= : 要建立在哪個子站，見 --site={site_id}}';

    protected $description = '建立廠商 App 並顯示一次 app_id／app_secret';

    public function handle(FactoryAppService $service): int
    {
        if (! $this->resolveSiteOrFail() && HqPresence::available()) {
            return self::FAILURE;
        }

        try {
            [$app, $key] = $service->create($this->argument('name'), null, null, null, $this->option('scope'), 'cli', (bool) $this->option('debug'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('已建立。app_secret 只顯示這一次，請立即保存：');
        $this->line("app_id : {$app->app_id}");
        $this->line("app_secret: {$key}");

        return self::SUCCESS;
    }
}
