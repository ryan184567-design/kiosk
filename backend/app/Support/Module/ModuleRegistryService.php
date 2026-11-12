<?php

/* *****************************************
File: ModuleRegistryService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 模組清單掃描、依賴展開、選單自動註冊，見 README 6.9。
***************************************** */

namespace App\Support\Module;

use App\Api\Menu\Models\Menu;
use App\Hq\Site\Models\Site;
use App\Hq\SiteModule\Models\Module;
use App\Hq\SiteModule\Models\SiteModule;
use Illuminate\Support\Str;
use RuntimeException;

// 模組清單掃描、依賴展開、選單自動註冊，見 README 6.9。跟 ModuleAccessService
// 同目錄、同樣「共用層直接引用 App\Hq\SiteModule\Models\*」的既有慣例
// （ModuleAccessService／SitePackageService 已經這樣做，不是新例外）。
//
// 這個類別故意混合兩種連線的操作，不拆成兩個類別：
//   - scanManifests()／syncToDatabase()／dependencyClosure()／
//     enabledCodesForSite()／manifestFor() 操作 Module/SiteModule（HQ
//     mysql 連線，見這兩個 Model 自己寫死的 $connection），跟任何子站
//     無關，掃描本身回答的是「產品線總共有哪些模組」。
//   - provisionMenusForSite() 操作 Menu（site_current 連線），呼叫端要
//     自己先確保連線已經切到目標站——這支負責「選單自動註冊」，購買
//     決定（site_modules）與掃描結果（modules）是兩件事，這裡只是把
//     兩者的交集（enabledCodesForSite 算出來的清單）套用到子站的選單表。
// 兩種連線各自綁在對應 Model 上，混在同一個類別不會互相干擾。
class ModuleRegistryService
{
    // 見 README 9 節目錄慣例：模組本體在 app/Api/{ModuleStudly}，module.json
    // 放在同一個目錄下。子站客製化（app/Sites/{site_code}/...）刻意不掃描：
    // 客製化程式碼本來就等於客戶已經買了，不受這套購買篩選機制管轄。
    public function scanManifests(): array
    {
        $manifests = [];

        foreach (glob(app_path('Api/*/module.json')) as $file) {
            $dir = basename(dirname($file));
            $data = json_decode(file_get_contents($file), true);

            if (! is_array($data) || empty($data['code'])) {
                throw new RuntimeException("module.json 格式不合法：{$file}");
            }

            $expectedCode = Str::snake($dir);

            if ($data['code'] !== $expectedCode) {
                throw new RuntimeException(
                    "module.json 的 code（{$data['code']}）與目錄名推算出的代碼（{$expectedCode}）不一致：{$file}"
                );
            }

            $manifests[$data['code']] = [
                'code' => $data['code'],
                'name' => $data['name'] ?? $data['code'],
                'description' => $data['description'] ?? '',
                'is_core' => (bool) ($data['is_core'] ?? false),
                'sort' => (int) ($data['sort'] ?? 0),
                'depends' => $data['depends'] ?? [],
                'menus' => $data['menus'] ?? [],
                'dir' => $dir,
            ];
        }

        // 依賴必須指向這輪掃描到的其他模組，懸空依賴（打錯字、依賴的模組
        // 目錄不存在）在這裡就要中斷，不能等到開通/打包當下才發現。
        foreach ($manifests as $code => $manifest) {
            foreach ($manifest['depends'] as $dependency) {
                if (! isset($manifests[$dependency])) {
                    throw new RuntimeException("模組 {$code} 依賴的模組代碼不存在：{$dependency}");
                }
            }
        }

        return $manifests;
    }

    // 只動 modules 表，不碰任何子站的 menus——選單註冊是子站層級的事，
    // 見 provisionMenusForSite()。
    public function syncToDatabase(): array
    {
        $manifests = $this->scanManifests();

        foreach ($manifests as $manifest) {
            Module::query()->updateOrCreate(
                ['code' => $manifest['code']],
                [
                    'name' => $manifest['name'],
                    'description' => $manifest['description'],
                    'is_core' => $manifest['is_core'],
                    'sort' => $manifest['sort'],
                    'is_enabled' => true,
                ]
            );
        }

        return array_keys($manifests);
    }

    public function manifestFor(string $code): ?array
    {
        return $this->scanManifests()[$code] ?? null;
    }

    // 把一組模組代碼展開成含全部遞移依賴的完整集合，供開通、打包共用——
    // 買了 A、A 依賴 B，B 一定要一起算進「這個站啟用中的模組」，不論
    // site_modules 裡有沒有 B 自己的紀錄。
    public function dependencyClosure(array $codes): array
    {
        $manifests = $this->scanManifests();
        $result = [];
        $queue = $codes;

        while ($queue !== []) {
            $code = array_shift($queue);

            if (isset($result[$code])) {
                continue;
            }

            $result[$code] = true;

            foreach ($manifests[$code]['depends'] ?? [] as $dependency) {
                $queue[] = $dependency;
            }
        }

        return array_keys($result);
    }

    // 核心模組 + 已開通未停用的模組，經 dependencyClosure 展開後回傳。
    // 取代 SitePackageService::writeSiteModuleList 原本重複的判斷邏輯，
    // 也是 copyModules()／provisionMenusForSite() 共同的輸入來源。
    public function enabledCodesForSite(Site $site): array
    {
        $manifests = $this->scanManifests();
        $globallyEnabled = Module::query()->pluck('is_enabled', 'code');
        $codes = [];

        foreach ($manifests as $code => $manifest) {
            if ($manifest['is_core']) {
                $codes[] = $code;

                continue;
            }

            // 模塊對所有子站全域下架時，不論該站是否開通過都不算（見
            // README 6.9 第 1 點），跟 ModuleAccessService::isEnabledForSite
            // 的判斷保持一致。
            if (! ($globallyEnabled[$code] ?? false)) {
                continue;
            }

            $purchased = SiteModule::query()
                ->where('site_id', $site->site_id)
                ->where('module_code', $code)
                ->whereNotNull('enabled_at')
                ->whereNull('disabled_at')
                ->exists();

            if ($purchased) {
                $codes[] = $code;
            }
        }

        return $this->dependencyClosure($codes);
    }

    // 依模組宣告的 menus 逐筆 upsert 進「目前 site_current 連線」的選單表，
    // 跟 SiteBaselineSeeder::seedAdminMenu() 原本手寫 $items 迴圈做的事
    // 完全一樣，只是資料來源換成模組宣告。分組（group-account／
    // group-system 這類）不在這裡建立，維持少數固定的手動種子資料
    // （見 SiteBaselineSeeder），這裡只負責解析 group 找出對應的 parent_id。
    // 呼叫端要自行確保 site_current 連線已經指向目標站。
    public function provisionMenusForSite(array $enabledCodes): void
    {
        $manifests = $this->scanManifests();

        foreach ($enabledCodes as $code) {
            foreach ($manifests[$code]['menus'] ?? [] as $item) {
                $parentId = $item['group'] ?? null
                    ? Menu::query()->where('portal_key', $item['portal'])->where('key', $item['group'])->value('id')
                    : null;

                Menu::query()->updateOrCreate(
                    ['portal_key' => $item['portal'], 'key' => $item['key']],
                    [
                        'parent_id' => $parentId,
                        'module_code' => $code,
                        'title' => $item['title'],
                        'subtitle' => $item['subtitle'] ?? null,
                        'icon' => $item['icon'] ?? null,
                        'path' => $item['path'] ?? null,
                        'permission_code' => $item['permission_code'] ?? null,
                        'sort' => $item['sort'] ?? 0,
                        'is_enabled' => true,
                    ]
                );
            }
        }
    }
}
