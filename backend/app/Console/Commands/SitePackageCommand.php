<?php

/* *****************************************
File: SitePackageCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 單一客戶現場部署打包，見 README 6.8。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use App\Hq\Site\Services\SitePackageService;
use Illuminate\Console\Command;
use InvalidArgumentException;

// 單一客戶現場部署打包，見 README 6.8。核心原則：保留客製化目錄結構，
// 只做「篩選」不做「合併攤平」。
//
// 實際邏輯在 SitePackageService：主站後台（POST /api/hq/sites/{siteId}/package）
// 走同一份，指令這層只負責參數解析與終端機輸出（見開發約定第 11 點，CLI
// 輸出不受「禁止硬編碼文案」限制）。
class SitePackageCommand extends Command
{
    protected $signature = 'site:package
        {site_code : 要打包的子站代碼}
        {--output= : 輸出目錄，預設 storage/app/packages/[site_code]}';

    protected $description = '打包單一客製化子站為現場部署包（見 README 6.8）';

    public function handle(SitePackageService $packager): int
    {
        $siteCode = $this->argument('site_code');
        $site = Site::query()->where('code', $siteCode)->first();

        if (! $site) {
            $this->error("找不到站點：{$siteCode}");

            return self::FAILURE;
        }

        // CLI 允許 --output 指定任何路徑（現場工程師輸出到 D:\deploy\xxx），
        // 不經過後台那套「只能寫在 storage/app/packages 底下」的限制。
        $output = $this->option('output') ?: $packager->defaultOutputPath($siteCode);

        try {
            $result = $packager->package($site, $output);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("打包完成：{$result['output_path']}（{$result['file_count']} 個檔案）");
        $this->warn('打包不做程式碼合併，見 6.8 第 7 點：共用程式碼不可有寫死判斷其他 site_code 的例外邏輯。');
        $this->warn('已產生可直接使用的 .env（APP_KEY/JWT secret 自動產生）與 DEPLOY.md 現場部署步驟，見 6.8 第 3、8 點。');

        return self::SUCCESS;
    }
}
