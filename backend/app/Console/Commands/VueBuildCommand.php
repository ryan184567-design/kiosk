<?php

/* *****************************************
File: VueBuildCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 編譯子站前端：前台 Vue 頁面（共用／dedicated）＋子站後台（admin），見 README 6.1～6.3。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// 編譯子站前端，見 README 6.1～6.3。
//
// 子站前端有兩套：前台（resources/vue_src，可依站 shared/dedicated）與
// 後台（resources/vue_src_admin，全站共用，固定輸出 public/admin-assets）。
// 兩套都屬於「子站」，實務上常只記得跑 vue:build 而漏掉 admin:build，
// 造成後台改動不生效，所以這支指令預設一併建置後台，不需要時用 --no-admin。
class VueBuildCommand extends Command
{
    protected $signature = 'vue:build
        {--site= : 指定站點代碼：dedicated 站輸出到 public/vue-sites/站點代碼，shared 站改建共用 public/vue（見 6.3）}
        {--clean : 清除舊檔案後重新建置（vite 設定一律 emptyOutDir，此參數僅為相容舊文件寫法）}
        {--all-sites : 建置共用前台＋所有 vue_build_mode=dedicated 的站點（CI 用，見 6.3）}
        {--no-admin : 不要一併建置子站後台（admin，public/admin-assets）}
        {--output= : 覆寫前台輸出目錄（相對專案根目錄，例如 public/vue）；單站部署包用，見 DEPLOY.md 第 6 點}
        {--base= : 搭配 --output 覆寫資源 base 路徑，預設 /vue/}';

    protected $description = '編譯子站前端：前台 Vue 頁面＋子站後台 admin（見 README 6.1～6.3）';

    public function handle(): int
    {
        $result = match (true) {
            (bool) $this->option('all-sites') => $this->buildAllSites(),
            (bool) $this->option('site') => $this->buildSite((string) $this->option('site')),
            default => $this->buildOne(null, dedicated: false),
        };

        if ($result !== self::SUCCESS || $this->option('no-admin')) {
            return $result;
        }

        return $this->buildAdmin();
    }

    // 共用前台＋所有 dedicated 站。共用前台一定要建：shared 站全部吃這一份，
    // 沒有 dedicated 站時至少也要讓 shared 站的修改生效。
    private function buildAllSites(): int
    {
        $failed = $this->buildOne(null, dedicated: false) === self::SUCCESS ? 0 : 1;

        $sites = Site::query()->where('vue_build_mode', 'dedicated')->where('is_enabled', true)->get();

        if ($sites->isEmpty()) {
            $this->line('沒有 vue_build_mode=dedicated 的站點，略過獨立建置。');
        }

        foreach ($sites as $site) {
            if ($this->buildOne($site->code, dedicated: true) !== self::SUCCESS) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // --site：依站點的 vue_build_mode 決定建置方式。shared 站實際讀的是
    // public/vue，若照字面建到 public/vue-sites/{code} 不會有任何地方用到，
    // 看起來像「建置成功但沒生效」，所以改建共用前台並提示。
    // 帶 --output（單站部署包）或單站模式（沒有主站 sites 表）時維持原本的獨立建置。
    private function buildSite(string $code): int
    {
        if ($this->option('output') || ! config('multisite.enabled')) {
            return $this->buildOne($code, dedicated: true);
        }

        $site = Site::query()->where('code', $code)->first();

        if (! $site) {
            $this->error("找不到站點代碼：{$code}");

            return self::FAILURE;
        }

        if ($site->vue_build_mode !== 'dedicated') {
            $this->warn("站點 {$code} 的 vue_build_mode 為 {$site->vue_build_mode}，前台使用共用建置 public/vue，改為建置共用前台。");

            return $this->buildOne(null, dedicated: false);
        }

        return $this->buildOne($code, dedicated: true);
    }

    private function buildOne(?string $siteCode, bool $dedicated): int
    {
        $outDir = $dedicated ? "public/vue-sites/{$siteCode}" : 'public/vue';
        $base = $dedicated ? "/vue-sites/{$siteCode}/" : '/vue/';

        // --output：單站部署包把 dedicated 建置直接輸出到 public/vue，
        // base 預設跟著回 /vue/（單站只有一份前台），需要其他值再用 --base。
        if ($override = $this->option('output')) {
            $override = trim(str_replace('\\', '/', $override), '/');

            if ($override === '' || str_contains($override, '..') || ! preg_match('#^[A-Za-z0-9_\-./]+$#', $override)) {
                $this->error('--output 只接受專案內的相對路徑，且不可含 ..');

                return self::FAILURE;
            }

            $outDir = $override;
            $base = $this->option('base') ?: '/vue/';
        }

        $label = '前台 '.($siteCode ?? '共用');

        return $this->runNpm('vue:build', $label, $outDir, [
            'VUE_BUILD_OUTDIR' => $outDir,
            'VUE_BUILD_BASE' => $base,
        ]);
    }

    // 子站後台全站共用一份（vite.admin.config.js 固定輸出 public/admin-assets），
    // --all-sites 也只建一次。
    private function buildAdmin(): int
    {
        return $this->runNpm('admin:build', '子站後台 admin', 'public/admin-assets');
    }

    private function runNpm(string $script, string $label, string $outDir, array $env = []): int
    {
        $this->line("建置 {$label} -> {$outDir}");

        $result = Process::path(base_path())
            ->timeout(300)
            ->env($env)
            ->run($this->npmCommand($script));

        if (! $result->successful()) {
            $this->error("建置失敗（{$label}）：".$result->errorOutput().$result->output());

            return self::FAILURE;
        }

        $this->info("建置完成：{$outDir}");

        return self::SUCCESS;
    }

    // Windows 上 npm 是 npm.cmd，跨平台呼叫方式不同。
    private function npmCommand(string $script): string
    {
        return (Str::startsWith(PHP_OS_FAMILY, 'Windows') ? 'npm.cmd' : 'npm')." run {$script}";
    }
}
