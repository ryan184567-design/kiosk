<?php

/* *****************************************
File: EnsureSiteContext.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.4：動態資料庫連線註冊。
***************************************** */

namespace App\Support\Http\Middleware;

use App\Support\Site\SiteContext;
use App\Support\Site\SiteOverrideResolver;
use App\Support\Site\SiteResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

// 見 README 6.4：動態資料庫連線註冊。排在所有會存取資料庫的 middleware 之前。
// 只掛在子站路由（app/Api、app/Web）；app/Hq 路由完全不經過這個 middleware，
// 因為主站用的是預設 mysql 連線，見 6.1、6.5。
class EnsureSiteContext
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly SiteOverrideResolver $overrideResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->resolver->resolve($request);

        Config::set('database.connections.site_current', array_merge(
            config('database.connections.site_current'),
            $config['db'] ?? [],
        ));
        DB::purge('site_current');

        app()->instance(SiteContext::class, new SiteContext(
            siteId: (int) $config['site_id'],
            siteType: (string) $config['site_type'],
            templateNamespace: (string) $config['template_namespace'],
            vueBuildMode: (string) ($config['vue_build_mode'] ?? 'shared'),
            code: (string) ($config['code'] ?? ''),
        ));

        $this->prependSiteViewPath((string) $config['template_namespace']);

        // 見 README 9.2：客製化子站的 Controller/Service 覆寫，在這裡（判斷
        // 出目前站點之後）才決定實際綁定哪個類別，只影響這次請求的容器
        // 生命週期，不影響其他站點的請求。
        $this->overrideResolver->register(app(), (string) $config['template_namespace']);

        return $next($request);
    }

    // 見 README 8.5：子站若有自己的 Blade 覆寫（resources/views/sites/{code}），
    // 插入查找路徑最前面；沒有這個目錄就完全不影響，Blade 照舊只查公版路徑
    // （resources/views/{module}），不需要每個子站都補一份空白繼承檔案才能
    // 跑（見 8.1 Tier 1「無 site-overrides 目錄，天然就是這層」的同一個原則）。
    private function prependSiteViewPath(string $templateNamespace): void
    {
        $overridePath = resource_path("views/sites/{$templateNamespace}");

        if (! is_dir($overridePath)) {
            return;
        }

        $finder = View::getFinder();
        $paths = $finder->getPaths();

        // Octane／長駐 worker 會重用同一個 finder 實例處理多個請求，這裡防止
        // 同一個路徑被反覆插入變成一長串重複項，見 6.4 Octane 風險說明。
        if (in_array($overridePath, $paths, true)) {
            return;
        }

        array_unshift($paths, $overridePath);
        $finder->setPaths($paths);
    }
}
