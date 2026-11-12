<?php

/* *****************************************
File: SiteResolver.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 判斷目前請求屬於哪個子站，見 README 6.1、6.4。
***************************************** */

namespace App\Support\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

// 判斷目前請求屬於哪個子站，見 README 6.1、6.4。
// 多站模式：網域優先 -> 路徑前綴 `{code}`（不帶底線，例如 /vendora/...，
// 頁面請求靠網址路徑本身、API 請求靠 X-Site-Path-Prefix header，見下方
// 第 2 點的說明與教訓） -> 預設（1 號子站）。
// 單站模式（MULTI_SITE_ENABLED=false）：不判斷，固定回傳 SITE_TEMPLATE_NAMESPACE
// 對應的站點資訊，見 6.8。
//
// 【已實測踩過的坑，務必記住】：路徑前綴判斷讀的是「這次請求自己的網址」，
// 不是「瀏覽器網址列現在顯示的頁面網址」——SPA 前端呼叫 `fetch('/api/xxx')`
// 這種絕對路徑時，請求本身的路徑就是 `/api/xxx`，不會自動帶著使用者當下
// 瀏覽的 `/vendora/xxx` 網址前綴，`$request->segment(1)` 對這個請求來說
// 永遠是 `api`，不是 `vendora`。這代表如果只做網址路徑判斷，光靠路徑前綴
// 進站的子站，其 SPA 頁面雖然能正確載入（頁面請求本身有帶前綴），但頁面
// 載入後所有 API 呼叫都會悄悄回退成預設站，形同整個管理後台/前台在操作
// 錯的資料庫卻毫無提示——已實測（管理後台建立角色，資料寫進 1 號站而不是
// 訪問網址上的子站）發現這個問題，不是理論疑慮。
// 解法：SPA 端把「目前瀏覽網址的站點前綴」記在自己知道的地方（見各 SPA
// 的 fetch 封裝），每次呼叫 API 額外帶一個 `X-Site-Path-Prefix` header，
// 這裡收到後跟路徑判斷用同一份站點代碼清單比對；沒有這個 header 時完全
// 不影響現有的頁面路徑判斷邏輯（向下相容，網域綁定的子站兩種請求都不需要
// 這個 header 也能正確判斷）。
class SiteResolver
{
    private ?array $sitesIndex = null;

    public function resolve(Request $request): array
    {
        if (! config('multisite.enabled')) {
            return $this->singleSiteConfig();
        }

        $index = $this->loadSitesIndex();
        $host = $request->getHost();

        foreach ($index as $siteId => $entry) {
            if (in_array($host, $entry['domains'] ?? [], true)) {
                return $this->loadSiteConfig((int) $siteId);
            }
        }

        // 見上方說明：兩個來源都要各自試一次，不能用 ?: 互斥擇一——
        // segment(1) 對 /api/xxx 這種請求永遠是字面上的 "api"（truthy），
        // 若用 ?: 會導致 header 永遠沒機會被檢查，已實測踩過這個坑
        // （改用 ?: 寫法時，帶了 header 的 API 呼叫依然被誤判成預設站）。
        foreach ([$request->segment(1), $request->header('X-Site-Path-Prefix')] as $candidate) {
            if (! $candidate) {
                continue;
            }

            foreach ($index as $siteId => $entry) {
                if (($entry['code'] ?? null) === $candidate) {
                    return $this->loadSiteConfig((int) $siteId);
                }
            }
        }

        // 找不到就回退預設站（1 號子站，見 6.1）。
        return $this->loadSiteConfig(1);
    }

    private function loadSitesIndex(): array
    {
        if ($this->sitesIndex !== null) {
            return $this->sitesIndex;
        }

        $path = storage_path('app/sites/sites.json');

        return $this->sitesIndex = File::exists($path)
            ? json_decode(File::get($path), true) ?? []
            : [];
    }

    private function loadSiteConfig(int $siteId): array
    {
        $path = storage_path("app/sites/{$siteId}.php");

        abort_unless(File::exists($path), 500, "站點設定檔不存在：{$path}");

        return require $path;
    }

    private function singleSiteConfig(): array
    {
        // 單站模式的站點識別碼一律從 config 取，不用 env()——config:cache 後
        // env() 會回傳 null，見 SiteDatabaseConfig 的說明。
        $siteId = (int) config('multisite.single_site.site_id', 1);
        $templateNamespace = (string) config('multisite.single_site.template_namespace', 'default');

        return [
            'site_id' => $siteId,
            'code' => $templateNamespace,
            'site_type' => 'standard',
            'template_namespace' => $templateNamespace,
            'vue_build_mode' => 'shared',
            // 單站模式回退用預設連線（.env 的 DB_HOST/DB_DATABASE）的設定，不做
            // 多站切換，見 6.1；讀寫分離沿用預設連線，見 2.5。
            'db' => SiteDatabaseConfig::singleSiteConnection(),
        ];
    }
}
