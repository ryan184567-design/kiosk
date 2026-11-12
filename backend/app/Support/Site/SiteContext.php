<?php

/* *****************************************
File: SiteContext.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 目前請求所屬站點的上下文，由 EnsureSiteContext middleware 綁定為容器單例， 見 README 6.4。
***************************************** */

namespace App\Support\Site;

// 目前請求所屬站點的上下文，由 EnsureSiteContext middleware 綁定為容器單例，
// 見 README 6.4。
class SiteContext
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteType,
        public readonly string $templateNamespace,
        public readonly bool $isHq = false,
        // 'shared' 或 'dedicated'，見 README 8.1，決定 SPA fallback 回傳哪個
        // 目錄下的 index.html。
        public readonly string $vueBuildMode = 'shared',
        // 站點代碼，即路徑前綴進站時網址的第一段（見 6.1）。SpaFallbackController
        // 靠這個判斷「裸網域根目錄」該導去哪個 /admin 網址（見該檔案說明）。
        public readonly string $code = '',
    ) {}

    public static function forHq(): self
    {
        return new self(siteId: 0, siteType: 'hq', templateNamespace: 'Hq', isHq: true);
    }
}
