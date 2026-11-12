<?php

/* *****************************************
File: ProvisionSiteJob.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.3「資源耗盡與失敗補償」第 2 點：建站流程改為非同步，避免 同步阻塞請求。
***************************************** */

namespace App\Jobs;

use App\Hq\Site\Models\Site;
use App\Hq\Site\Services\SiteProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// 見 README 6.3「資源耗盡與失敗補償」第 2 點：建站流程改為非同步，避免
// 同步阻塞請求。HTTP 端點（POST /api/hq/sites）派送這個 Job，CLI（site:create）
// 直接同步呼叫 SiteProvisioningService，不經過 Job。
class ProvisionSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $siteId) {}

    public function handle(SiteProvisioningService $provisioning): void
    {
        $site = Site::query()->find($this->siteId);

        if (! $site) {
            return;
        }

        $provisioning->provision($site);
    }
}
