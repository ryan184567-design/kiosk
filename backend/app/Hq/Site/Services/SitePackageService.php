<?php

/* *****************************************
File: SitePackageService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 單一客戶現場部署打包的共用邏輯，見 README 6.8。
***************************************** */

namespace App\Hq\Site\Services;

use App\Hq\Site\Models\Site;
use App\Support\Module\ModuleRegistryService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

// 單一客戶現場部署打包的共用邏輯，見 README 6.8。核心原則：保留客製化
// 目錄結構，只做「篩選」不做「合併攤平」。
//
// CLI（site:package）與主站後台（POST /api/hq/sites/{siteId}/package）共用
// 同一份，不讓兩邊各寫一套。這種「要複製哪些目錄／要排除哪些目錄」的規則
// 一旦分岔，最糟的後果是某天從後台輸出的部署包悄悄夾帶了其他客戶的客製化
// 程式碼，而 CLI 輸出的是乾淨的，兩邊行為不一致卻沒地方看得出來。
class SitePackageService
{
    // 共用程式碼：原封不動整包複製，見 6.8 第 1 點。主站專屬的 app/Hq 與
    // database/hq 刻意不在清單內（單站部署不需要站點管理功能）。
    // app/Api、app/Web 刻意不在這份清單——這兩個目錄底下的模組原始碼要
    // 依購買狀態逐一篩選（見 copyModules()），不是整包複製；
    // app/Web/Sso、app/Web/Front 這種不對應任何 modules 登記的既有骨架
    // 目錄，在 copyModules() 裡另外處理，一律複製。
    private const SHARED_PATHS = [
        'app/Support',
        'app/Plugins',
        'app/Console',
        'app/Models',
        'app/Providers',
        'app/Http',
        // app/Jobs 刻意不在清單內：目前只有主站用的 ProvisionSiteJob（依賴
        // app/Hq），單站部署包不需要；日後若新增與站點無關的共用 Job，記得
        // 把 app/Jobs 加進來，否則匯出包會少檔案。
        'config',
        'database/migrations',
        'database/seeders',
        'database/factories',
        'routes',
        'bootstrap',
        'public',
        'resources/views',
        'resources/vue_src',
        // 子站後台原始碼：admin:build 需要。主站後台 vue_src_hq 與
        // vite.hq.config.js 刻意不帶（單站部署包不含站點管理）。
        'resources/vue_src_admin',
        'resources/css',
        'resources/js',
        'artisan',
        'composer.json',
        'composer.lock',
        // 前端建置需要：現場 npm ci 後才能 admin:build／vue:build。
        // package.json 內的 hq:* scripts 會留著但無對應原始碼，不影響其他 script。
        'package.json',
        'package-lock.json',
        'vite.config.js',
        'vite.vue.config.js',
        'vite.admin.config.js',
        // 附上完整的環境變數範本：匯出時產生的 .env 就是從它改寫而來，
        // 現場工程師也能從它看出有哪些可調整的設定（原本只給五行 .env.package
        // 提示，結果是現場得自己拼一份殘缺的 .env，缺 APP_KEY 直接起不來）。
        '.env.example',
    ];

    // storage/ 骨架，見 6.8：Laravel 需要這些目錄才能運作（尤其 framework/views
    // 是 Blade 編譯輸出、logs 是寫 log、framework/sessions 與 cache/data 是
    // session/cache 落地位置）。匯出包只建立空目錄與 .gitignore，不複製開發機
    // 的內容（編譯過的 Blade、舊 log、上傳檔案都不該帶到客戶機房）。
    private const STORAGE_DIRECTORIES = [
        'storage/app',
        'storage/app/private',
        'storage/app/public',
        'storage/framework',
        'storage/framework/cache',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
    ];

    public function __construct(private readonly ModuleRegistryService $moduleRegistry) {}

    // 輸出目錄預設位置，見 6.8：輸出到獨立目錄，不影響原開發庫。
    public function defaultOutputPath(string $siteCode): string
    {
        return storage_path("app/packages/{$siteCode}");
    }

    // 把後台傳入的「輸出資料夾」解析成實際路徑，只接受儲存在
    // storage/app/packages 底下的相對路徑——這支 API 是給主站超級管理員用
    // 的，但讓一個 HTTP 請求帶著任意絕對路徑去寫檔（例如系統目錄）仍然是不
    // 必要的風險，能收斂就收斂。CLI 走 --output 時不經過這裡，現場工程師要
    // 直接輸出到 D:\deploy\xxx 仍然可以（見 6.8、18 第 16 點）。
    /**
     * @throws InvalidArgumentException 資料夾格式不合法（含 .. 或非英數底線斜線）
     */
    public function resolveOutputPath(Site $site, ?string $folder): string
    {
        $folder = trim((string) $folder);

        if ($folder === '') {
            return $this->defaultOutputPath($site->code);
        }

        if (! preg_match('/^[A-Za-z0-9._\-\/]+$/', $folder) || str_contains($folder, '..')) {
            throw new InvalidArgumentException(__('hq.packaging.invalid_output'));
        }

        return storage_path('app/packages/'.trim($folder, '/'));
    }

    /**
     * 產生單站部署包，回傳輸出位置與檔案統計供後台/CLI 顯示。
     *
     * @return array{output_path: string, file_count: int, size_bytes: int}
     *
     * @throws InvalidArgumentException 站點尚未就緒，或輸出目錄已存在（避免覆蓋）
     */
    public function package(Site $site, string $output): array
    {
        // 建置中/失敗的子站連自己的資料庫都還沒好，輸出的部署包在現場也跑不
        // 起來，直接擋掉比讓人生成一包不能用的東西好。CLI 過去沒有這層檢查，
        // 這裡補上並在 README 6.8 說明。
        if ($site->provision_status !== 'ready') {
            throw new InvalidArgumentException(__('hq.packaging.site_not_ready'));
        }

        if (File::isDirectory($output)) {
            throw new InvalidArgumentException(__('hq.packaging.output_exists', ['path' => $output]));
        }

        File::ensureDirectoryExists($output);

        $this->copyShared($output);
        $this->copyModules($output, $site);
        $this->copySiteSpecific($output, $site->code);
        $this->excludeOtherSites($output, $site->code);

        // 讓匯出包「當場可跑」的三件事，見 6.8：沒有這幾步，匯出的東西只是原始碼
        // 備份，現場一定會撞到 storage 不存在、.env 缺 APP_KEY、開發機快取殘留。
        $this->writeStorageSkeleton($output);
        $this->cleanBootstrapCache($output);
        $this->writeEnvFile($output, $site);
        $this->writeSiteModuleList($output, $site);
        $this->writeDeployGuide($output, $site);

        $files = File::allFiles($output);

        return [
            'output_path' => $output,
            'file_count' => count($files),
            'size_bytes' => (int) array_sum(array_map(fn ($file) => $file->getSize(), $files)),
        ];
    }

    private function copyShared(string $output): void
    {
        foreach (self::SHARED_PATHS as $path) {
            $source = base_path($path);

            if (! File::exists($source)) {
                continue;
            }

            $target = "{$output}/{$path}";

            if (File::isDirectory($source)) {
                File::ensureDirectoryExists($target);
                File::copyDirectory($source, $target);
            } else {
                File::ensureDirectoryExists(dirname($target));
                File::copy($source, $target);
            }
        }
    }

    // 依模組購買狀態（含依賴展開，見 ModuleRegistryService::enabledCodesForSite）
    // 逐一篩選 app/Api、app/Web 底下的模組原始碼，不是像其他共用目錄一樣
    // 整包複製（見 SHARED_PATHS 註解）——基礎包一定在，沒買的模組原始碼
    // 不會出現在部署包裡，見 README 6.8/6.9。app/Sites/{site_code} 客製化
    // 目錄完全不受這裡影響（見 copySiteSpecific()）：客製化本來就等於
    // 客戶已經買了，不跟這套購買篩選掛鉤。
    private function copyModules(string $output, Site $site): void
    {
        $manifests = $this->moduleRegistry->scanManifests();
        $enabled = array_flip($this->moduleRegistry->enabledCodesForSite($site));

        foreach ($manifests as $code => $manifest) {
            if (! isset($enabled[$code])) {
                continue;
            }

            $this->copyDirectoryIfExists(base_path("app/Api/{$manifest['dir']}"), "{$output}/app/Api/{$manifest['dir']}");

            // 這個模組宣告在哪些端口有選單，對應可能存在的
            // app/Web/{Portal}/{目錄} 衛星頁面（見 module.json 的
            // menus[].portal），一併帶走——「模組可能在多個端口都有頁面」
            // 就是靠這裡把每個端口各自的頁面目錄都篩進來。
            foreach (array_unique(array_column($manifest['menus'], 'portal')) as $portal) {
                $portalDir = Str::studly($portal);
                $this->copyDirectoryIfExists(
                    base_path("app/Web/{$portalDir}/{$manifest['dir']}"),
                    "{$output}/app/Web/{$portalDir}/{$manifest['dir']}"
                );
            }
        }

        // 不對應任何模組登記、純骨架用途的既有目錄（SSO 落地頁、前台骨架），
        // 不受這套篩選影響，一律複製；日後若新增其他這類「跟模組無關的
        // app/Web 骨架目錄」，記得補進這個清單，否則會被漏掉。
        foreach (['app/Web/Sso', 'app/Web/Front'] as $path) {
            $this->copyDirectoryIfExists(base_path($path), "{$output}/{$path}");
        }
    }

    private function copyDirectoryIfExists(string $source, string $target): void
    {
        if (File::isDirectory($source)) {
            File::ensureDirectoryExists($target);
            File::copyDirectory($source, $target);
        }
    }

    private function copySiteSpecific(string $output, string $siteCode): void
    {
        $siteStudly = Str::studly($siteCode);

        $pairs = [
            "app/Sites/{$siteStudly}" => "app/Sites/{$siteStudly}",
            "resources/views/sites/{$siteCode}" => "resources/views/sites/{$siteCode}",
            "resources/vue_src/src/site-overrides/{$siteCode}" => "resources/vue_src/src/site-overrides/{$siteCode}",
            "database/sites/{$siteCode}" => "database/sites/{$siteCode}",
        ];

        foreach ($pairs as $source => $target) {
            $sourcePath = base_path($source);

            if (File::isDirectory($sourcePath)) {
                File::ensureDirectoryExists("{$output}/{$target}");
                File::copyDirectory($sourcePath, "{$output}/{$target}");
            }
        }
    }

    private function excludeOtherSites(string $output, string $siteCode): void
    {
        // 保險起見，複製共用目錄時若不小心帶到其他站的客製化子目錄，這裡強制清掉，
        // 見 6.8 第 1 點「排除其他客戶的客製化目錄」。
        foreach (['app/Sites', 'database/sites'] as $base) {
            $dir = "{$output}/{$base}";

            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::directories($dir) as $sub) {
                if (basename($sub) !== Str::studly($siteCode) && basename($sub) !== $siteCode) {
                    File::deleteDirectory($sub);
                }
            }
        }
    }

    // 建立 storage/ 骨架。目錄權限與 .gitignore 都比照開發庫（能複製就複製，
    // 沒得複製時用 Laravel 官方骨架的內容），讓現場不用自己猜要建哪些目錄。
    private function writeStorageSkeleton(string $output): void
    {
        foreach (self::STORAGE_DIRECTORIES as $dir) {
            File::ensureDirectoryExists("{$output}/{$dir}");

            $source = base_path("{$dir}/.gitignore");
            $target = "{$output}/{$dir}/.gitignore";

            if (File::exists($source)) {
                File::copy($source, $target);

                continue;
            }

            File::put($target, "*\n!.gitignore\n");
        }
    }

    // 清掉開發機帶來的 bootstrap 快取（packages.php／services.php／config.php／
    // routes-*.php）。這些是「這台開發機當時的 vendor 內容」的快照，現場
    // composer install 後的 vendor 不一定相同，帶著跑輕則載入不存在的
    // provider、重則直接開不起來；.gitignore 保留，目錄本身要留著。
    private function cleanBootstrapCache(string $output): void
    {
        $dir = "{$output}/bootstrap/cache";

        if (! File::isDirectory($dir)) {
            return;
        }

        foreach (File::files($dir) as $file) {
            if ($file->getFilename() !== '.gitignore') {
                File::delete($file->getPathname());
            }
        }
    }

    // 產生可直接使用的 .env，見 6.8 第 3 點。做法是以 .env.example 為底改寫，
    // 而不是自己拼一份五行設定——原本那種做法導致現場只知道資料庫要填什麼，
    // 其餘（APP_KEY、JWT secret、SESSION_DRIVER…）全缺，抄漏一個就 500。
    private function writeEnvFile(string $output, Site $site): void
    {
        $template = base_path('.env.example');
        $contents = File::exists($template)
            ? File::get($template)
            : "APP_NAME=WebBase\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\n";

        $overrides = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $site->primary_domain ? "http://{$site->primary_domain}" : 'http://localhost',
            // 每個部署包各自產生，不沿用開發機的密鑰（避免客戶機房拿到開發用密鑰，
            // 也讓各客戶之間的 token/加密資料無法互通）。
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'JWT_SITE_SECRET' => bin2hex(random_bytes(32)),
            'JWT_HQ_SECRET' => bin2hex(random_bytes(32)),
            'DB_DATABASE' => $site->db_name,
            'MULTI_SITE_ENABLED' => 'false',
            'SITE_ID' => (string) $site->site_id,
            'SITE_TEMPLATE_NAMESPACE' => $site->template_namespace,
        ];

        $header = <<<TXT
        # ------------------------------------------------------------------
        # 本檔由主站 site:package 匯出時自動產生（見 README 6.8 第 3 點）。
        # DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD、APP_URL 請依現場環境調整；
        # APP_KEY 與 JWT_*_SECRET 已自動產生，部署後請勿隨意更換。
        # 現場部署步驟見同目錄的 DEPLOY.md。
        # ------------------------------------------------------------------

        TXT;

        File::put("{$output}/.env", $header.$this->applyEnvOverrides($contents, $overrides));
    }

    // 把 .env 範本裡對應的鍵換成指定值；範本裡是註解狀態（# KEY=）也要能就地
    // 取代成生效行，沒這個鍵就附加到檔案最後。
    private function applyEnvOverrides(string $contents, array $overrides): string
    {
        foreach ($overrides as $key => $value) {
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
            $line = "{$key}={$value}";

            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
                : rtrim($contents, "\r\n")."\n{$line}\n";
        }

        return $contents;
    }

    // 把「這個站買了哪些模塊」固化進部署包，見 6.9、6.8。
    //
    // 為什麼需要：模塊開通清單（modules／site_modules）只存在主站資料庫，而
    // 部署包刻意不含 app/Hq 與主站資料庫。若不固化這份清單，單站環境根本無法
    // 判斷哪些模塊已購買——實際上就是「共用層查不到 modules 而直接 500」。
    // 固化之後，ModuleAccessService 在單站模式改讀這個檔案，6.9 的「依模塊
    // 購買、子站需個別開通」在現場仍然成立，不會因為換成單站就整組解鎖。
    private function writeSiteModuleList(string $output, Site $site): void
    {
        $enabled = $this->moduleRegistry->enabledCodesForSite($site);
        sort($enabled);

        $content = "<?php\n\n"
            ."// 由主站 site:package 匯出時產生，見 README 6.8、6.9：\n"
            ."// 這份部署包已開通的模塊代碼清單（含核心模塊），內容固定，現場不需修改。\n"
            .'return '.var_export($enabled, true).";\n";

        File::put("{$output}/storage/app/site_modules.php", $content);
    }

    // 現場部署說明（見 6.8）：從 resources/deploy/DEPLOY.md 範本置換站點資訊後
    // 放到部署包根目錄。步驟內容寫在範本檔案而不是程式碼裡，方便維運團隊直接
    // 改文件、不用動程式。
    private function writeDeployGuide(string $output, Site $site): void
    {
        $template = resource_path('deploy/DEPLOY.md');

        if (! File::exists($template)) {
            return;
        }

        File::put(
            "{$output}/DEPLOY.md",
            str_replace(
                ['{{site_code}}', '{{site_name}}', '{{site_id}}', '{{db_name}}', '{{site_type}}', '{{vue_build_mode}}'],
                [$site->code, $site->name, (string) $site->site_id, (string) $site->db_name, (string) $site->site_type, (string) $site->vue_build_mode],
                File::get($template),
            )
        );
    }
}
