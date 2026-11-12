<?php

/* *****************************************
File: ModuleSyncCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 掃描各模組目錄下的 module.json 並同步主站 modules 表，見 README 6.9。
***************************************** */

namespace App\Console\Commands;

use App\Support\Module\ModuleRegistryService;
use Illuminate\Console\Command;
use RuntimeException;

// 掃描 app/Api/*/module.json 並同步主站 modules 表，見 README 6.9。
// SiteBaselineSeeder 已經會在每次子站種子時自動呼叫同一套邏輯，日常開發
// 不用手動下這支指令；只有「新增了模組但沒有重跑任何子站 seeder」時才
// 需要，或想在不動任何子站資料的情況下單獨確認 module.json 寫得對不對。
class ModuleSyncCommand extends Command
{
    protected $signature = 'module:sync';

    protected $description = '掃描 app/Api/*/module.json 並同步主站 modules 表（見 README 6.9）';

    public function handle(ModuleRegistryService $registry): int
    {
        try {
            $codes = $registry->syncToDatabase();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($codes as $code) {
            $this->line("同步：{$code}");
        }

        $this->info('共同步 '.count($codes).' 個模組。');

        return self::SUCCESS;
    }
}
