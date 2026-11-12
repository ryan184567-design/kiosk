<?php

/* *****************************************
File: SiteCreateCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 自動建站流程，見 README 6.3。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Services\SiteProvisioningService;
use Illuminate\Console\Command;
use InvalidArgumentException;

// 自動建站流程，見 README 6.3。CLI 同步版本，供本機開發／首次啟動（18.1）
// 直接使用；HTTP 版本（POST /api/hq/sites）走 ProvisionSiteJob 非同步呼叫
// 同一個 SiteProvisioningService，兩邊邏輯不分岔。
class SiteCreateCommand extends Command
{
    protected $signature = 'site:create
        {code : 小寫英數底線}
        {name : 顯示名稱}
        {--type=standard : standard|customized}';

    protected $description = '建立一個新子站（見 README 6.3）';

    public function handle(SiteProvisioningService $provisioning): int
    {
        try {
            $site = $provisioning->createPending(
                $this->argument('code'),
                $this->argument('name'),
                $this->option('type'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $provisioning->provision($site)) {
            $this->error("建站失敗：{$site->provision_failed_reason}");
            $this->warn('可用 site:cleanup-failed 清理，見 README 6.3。');

            return self::FAILURE;
        }

        $this->info("子站建立完成：{$site->code}（site_id={$site->site_id}）");

        return self::SUCCESS;
    }
}
