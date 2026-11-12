<?php

/* *****************************************
File: VuePageMakeCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Vue 客製頁面骨架腳本，見 README 18 第 5 點、8.3、8.4。
***************************************** */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// Vue 客製頁面骨架腳本，見 README 18 第 5 點、8.3、8.4。
class VuePageMakeCommand extends Command
{
    protected $signature = 'vue:page:make
        {site_code : 子站代碼，小寫英數底線}
        {page_name : 頁面名稱，小寫英數連字號，例如 order-dashboard}';

    protected $description = '建立子站客製化 Vue 頁面骨架（見 README 8.3、8.4）';

    public function handle(): int
    {
        $siteCode = strtolower($this->argument('site_code'));
        $pageName = strtolower($this->argument('page_name'));

        if (! preg_match('/^[a-z0-9_]+$/', $siteCode)) {
            $this->error('site_code 只能是小寫英數底線。');

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z0-9-]+$/', $pageName)) {
            $this->error('page_name 只能是小寫英數連字號。');

            return self::FAILURE;
        }

        $componentName = Str::studly(str_replace('-', '_', $pageName));
        $baseDir = base_path("resources/vue_src/src/site-overrides/{$siteCode}");
        $componentPath = "{$baseDir}/pages/{$componentName}.vue";
        $routesFragmentPath = "{$baseDir}/routes.js";

        if (File::exists($componentPath)) {
            $this->error("頁面已存在：{$componentPath}");

            return self::FAILURE;
        }

        if (! File::isDirectory(base_path('resources/vue_src'))) {
            $this->warn('resources/vue_src 尚未建立（Vue3 專案骨架待建），先產生檔案，日後建好前端專案後即可直接使用。');
        }

        File::ensureDirectoryExists("{$baseDir}/pages");
        File::put($componentPath, $this->componentStub($componentName));

        $this->mergeRoutesFragment($routesFragmentPath, $siteCode, $pageName, $componentName);

        $this->info("客製頁面骨架已建立：{$componentPath}");
        $this->warn('見 8.1：Tier 2（動態掛載）直接沿用共用建置即可；若升級 Tier 3，改用 vue:build --site= 建置，原始碼位置不變。');

        return self::SUCCESS;
    }

    private function componentStub(string $componentName): string
    {
        return <<<VUE
        <template>
          <div class="{$componentName}">
            <!-- TODO: {$componentName} 內容 -->
          </div>
        </template>

        <script setup>
        // 客製頁面邏輯，見 README 8.3（Tier 2 動態掛載）/ 8.4（Tier 3 獨立建置）。
        </script>

        VUE;
    }

    private function mergeRoutesFragment(string $path, string $siteCode, string $pageName, string $componentName): void
    {
        if (! File::exists($path)) {
            File::put($path, "// 見 README 8.3：依 site.config 回傳的 custom_routes 清單，啟動時 router.addRoute() 動態掛載。\nexport default [\n];\n");
        }

        $this->line("記得在 {$path} 補上這筆路由（元件用 import() 動態載入，見 8.3）：");
        $this->line("  { path: '/{$pageName}', component: () => import('./pages/{$componentName}.vue') }");
    }
}
