<?php

/* *****************************************
File: VueBuildCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 編譯前台 Vue 頁面，見 README 18.2 第 15 項、8.1/8.4。
***************************************** */

namespace App\Console\Commands;

use App\Hq\Site\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// 編譯前台 Vue 頁面，見 README 18.2 第 15 項、8.1/8.4。
class VueBuildCommand extends Command
{
    protected $signature = 'vue:build
        {--site= : 指定站點代碼，Tier 3 獨立建置（見 8.4），輸出到 public/vue-sites/{site}}
        {--clean : 清除舊檔案後重新建置（本專案的 vite.vue.config.js 一律 emptyOutDir，此參數僅為相容 18.2 文件寫法）}
        {--all-sites : 重新建置所有 vue_build_mode=dedicated 的站點，見 8.4}
        {--output= : 覆寫輸出目錄（相對專案根目錄，例如 public/vue）；單站部署包用，見 DEPLOY.md 第 6 點}
        {--base= : 搭配 --output 覆寫資源 base 路徑，預設 /vue/}';

    protected $description = '編譯前台 Vue 頁面（見 README 8.1/8.4、18.2）';

    public function handle(): int
    {
        if ($this->option('all-sites')) {
            return $this->buildAllDedicated();
        }

        if ($site = $this->option('site')) {
            return $this->buildOne($site, dedicated: true);
        }

        return $this->buildOne(null, dedicated: false);
    }

    private function buildAllDedicated(): int
    {
        $sites = Site::query()->where('vue_build_mode', 'dedicated')->where('is_enabled', true)->get();

        if ($sites->isEmpty()) {
            $this->info('沒有 vue_build_mode=dedicated 的站點。');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($sites as $site) {
            if ($this->buildOne($site->code, dedicated: true) !== self::SUCCESS) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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

        $label = $siteCode ?? '共用';
        $this->line("建置 {$label} -> {$outDir}");

        $result = Process::path(base_path())
            ->timeout(300)
            ->env([
                'VUE_BUILD_OUTDIR' => $outDir,
                'VUE_BUILD_BASE' => $base,
            ])
            ->run($this->npmCommand());

        if (! $result->successful()) {
            $this->error("建置失敗（{$label}）：".$result->errorOutput());

            return self::FAILURE;
        }

        $this->info("建置完成：{$outDir}");

        return self::SUCCESS;
    }

    // Windows 上 npm 是 npm.cmd，跨平台呼叫方式不同。
    private function npmCommand(): string
    {
        return Str::startsWith(PHP_OS_FAMILY, 'Windows')
            ? 'npm.cmd run vue:build'
            : 'npm run vue:build';
    }
}
