<?php

/* *****************************************
File: SiteOverrideResolver.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 9.2：客製化子站的 Controller/Service 覆寫機制，約定優於配置、 自動偵測——不需要工程師手動註冊 binding，只要新增覆寫檔案就生效。
***************************************** */

namespace App\Support\Site;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// 見 README 9.2：客製化子站的 Controller/Service 覆寫機制，約定優於配置、
// 自動偵測——不需要工程師手動註冊 binding，只要新增覆寫檔案就生效。
//
// 機制：掃描 app/Sites/{SiteCode}/ 底下的類別，凡是「跟某個公版類別完全
// 同相對路徑、同類別名稱，且確實 extends 該公版類別」的，就在容器裡把
// 公版類別動態 bind 到覆寫類別；公版子站（沒有 app/Sites/{SiteCode} 目錄）
// 完全不受影響，is_dir() 直接短路，零成本（同 8.5 Blade fallback、8.1
// Tier 1「無 site-overrides 目錄，天然就是這層」的同一個設計語言）。
//
// 只對「透過容器解析」的依賴生效——這也是為什麼 9.2 明文禁止
// `new OrderService()` 直接建立實例，直接 new 永遠拿不到覆寫類別。
class SiteOverrideResolver
{
    public function register(Application $app, string $templateNamespace): void
    {
        $siteCode = Str::studly($templateNamespace);
        $siteRoot = app_path("Sites/{$siteCode}");

        if (! is_dir($siteRoot)) {
            return;
        }

        foreach ($this->discoverOverrides($siteRoot, $siteCode) as $sharedClass => $overrideClass) {
            $app->bind($sharedClass, fn ($app) => $app->make($overrideClass));
        }
    }

    /**
     * @return array<class-string, class-string> 公版類別 => 覆寫類別
     */
    private function discoverOverrides(string $siteRoot, string $siteCode): array
    {
        $overrides = [];

        foreach (File::allFiles($siteRoot) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getPathname(), $siteRoot.DIRECTORY_SEPARATOR)
            );

            $overrideClass = "App\\Sites\\{$siteCode}\\{$relative}";
            $sharedClass = "App\\{$relative}";

            // 找不到同名公版類別，代表這是 9.2 第二點「公版完全沒有的全新
            // API」，不是覆寫，略過（該類別由自己的 routes 直接引用，不需要
            // 透過這裡的 binding 機制）。找到了但沒有真的 extends，多半是
            // 命名巧合而非刻意覆寫，同樣略過，不強行綁定型別不相容的類別。
            if (! class_exists($sharedClass) || ! class_exists($overrideClass)) {
                continue;
            }

            if (! is_subclass_of($overrideClass, $sharedClass)) {
                continue;
            }

            $overrides[$sharedClass] = $overrideClass;
        }

        return $overrides;
    }
}
