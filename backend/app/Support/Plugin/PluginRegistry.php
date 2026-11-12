<?php

/* *****************************************
File: PluginRegistry.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 插件目錄掃描，見 README 6.10、17.4。供插件管理頁面列出「目前有哪些插件」，不是執行期解析（那是 PluginManager 的職責，見該檔）。
***************************************** */

namespace App\Support\Plugin;

use Illuminate\Support\Str;

// 插件目錄掃描，見 README 6.10、17.4。供插件管理頁面列出「目前有哪些
// 插件」，不是執行期解析（那是 PluginManager 的職責，見該檔）。這裡回傳
// 的是程式碼庫層級的「目錄」，全站共用、不分子站；子站各自的啟用狀態與
// 設定值另外查 site_plugin_configs／system_configs（見 catalog() 的呼叫端
// PluginAdminService）。
//
// 刻意分兩條掃描路徑，不是疏漏：
//   1. AD 這類走 6.10 標準插件骨架（PluginManager + site_plugin_configs，
//      支援多品牌/多版本同時存在、按站點分別啟用）。
//   2. SSO 是既有、獨立運作的一套 driver registry（config/sso.php，見
//      8.6），設定沿用原本就存在的 system_configs（cfg_module=sso）沒有
//      搬動，這裡只是額外把它也攤平進同一份插件目錄清單方便管理員瀏覽，
//      沒有強行套用 PluginManager 那套 site_plugin_configs 機制——兩套
//      機制目前並存，日後若有實際需求要合併，再依那個需求決定怎麼收斂
//      （見 README 6.10.7 的說明）。
class PluginRegistry
{
    // 見 6.10.1：目錄慣例固定是
    // app/Plugins/{FunctionStudly}/{BrandStudly}/{VERSION}/{FunctionStudly}Connector.php，
    // 直接讀目錄取得「程式碼庫裡實際存在哪些插件實作」，不只看
    // config/plugins.php——plugin:make 目前不會自動寫入 config（見該指令
    // 說明），只掃 config 會漏掉「已經寫好但還沒登記」的插件，管理員也
    // 無從得知它其實已經存在。
    public function scanConnectors(): array
    {
        $files = glob(app_path('Plugins'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*Connector.php')) ?: [];
        $byFunction = [];

        foreach ($files as $file) {
            $relative = Str::after(str_replace('\\', '/', $file), str_replace('\\', '/', app_path('Plugins')).'/');
            $parts = explode('/', $relative);

            if (count($parts) !== 4) {
                continue;
            }

            [$functionStudly, $brandStudly, $version, $filename] = $parts;
            $class = "App\\Plugins\\{$functionStudly}\\{$brandStudly}\\{$version}\\".pathinfo($filename, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            $functionCode = Str::snake($functionStudly);
            $brandVersionKey = Str::snake($brandStudly).'_'.strtolower($version);
            $registeredClass = config("plugins.{$functionCode}.{$brandVersionKey}");

            $byFunction[$functionCode][] = [
                'key' => $brandVersionKey,
                'brand' => $brandStudly,
                'version' => $version,
                'class' => $class,
                // 見上方說明：磁碟上找得到不代表能用，還得登記進
                // config/plugins.php（PluginManager::resolve 才認得到），
                // 這是刻意的信任邊界——不讓後台單靠掃到檔案就能執行任意
                // 找到的類別，登記本身要走部署流程人工確認。
                'is_registered' => $registeredClass === $class,
                'config_module' => $this->moduleCodeFor($functionCode, $brandVersionKey),
                'schema' => method_exists($class, 'configSchema') ? $class::configSchema() : [],
            ];
        }

        return $byFunction;
    }

    // 見上方類別說明第 2 點：SSO 沿用既有的 config/sso.php 驅動清單，
    // 不是從目錄掃描——SsoProvider 實作可能放在 app/Support/Sso/Drivers
    // 這種不遵循 6.10.1 目錄慣例的位置（歷史原因），沒有固定的目錄結構
    // 可掃。
    public function ssoDrivers(): array
    {
        $entries = [];

        foreach (config('sso.drivers', []) as $key => $class) {
            if (! class_exists($class)) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'class' => $class,
                'is_registered' => true,
                'config_module' => 'sso',
                'schema' => method_exists($class, 'configSchema') ? $class::configSchema() : [],
            ];
        }

        return $entries;
    }

    // sso 固定沿用既有的單一模組（見 SsoSiteConfig，歷史資料不搬動）；
    // 其餘一般插件用 plugin:{function}:{key} 命名，同一個 function 底下
    // 不同品牌/版本各自獨立一個模組，設定互不干擾。
    public function moduleCodeFor(string $function, string $key): string
    {
        return $function === 'sso' ? 'sso' : "plugin:{$function}:{$key}";
    }
}
