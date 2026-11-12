<?php

/* *****************************************
File: ModuleMakeCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 模組初始化腳本，見 README 18 第 3 點、9.2。
***************************************** */

namespace App\Console\Commands;

use App\Support\Module\ModuleRegistryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

// 模組初始化腳本，見 README 18 第 3 點、9.2。
class ModuleMakeCommand extends Command
{
    protected $signature = 'module:make
        {module_code : 小寫英數底線，例如 order}
        {--portal= : 若此模塊的 Controller 要加 portal 前綴，見 9.1.1}
        {--site= : 子站客製化全新模組，放在 app/Sites/[SiteCode]/Api/[module]}';

    protected $description = '建立新模組骨架（公版或子站客製化，見 README 18、9.2）';

    public function handle(): int
    {
        $moduleCode = $this->argument('module_code');

        if (! preg_match('/^[a-z0-9_]+$/', $moduleCode)) {
            $this->error('module_code 只能是小寫英數底線。');

            return self::FAILURE;
        }

        $moduleStudly = Str::studly($moduleCode);
        $portal = $this->option('portal');
        $siteCode = $this->option('site');

        $basePath = $siteCode
            ? app_path('Sites/'.Str::studly($siteCode)."/Api/{$moduleStudly}")
            : app_path("Api/{$moduleStudly}");

        if (File::isDirectory($basePath)) {
            $this->error("模組目錄已存在：{$basePath}");

            return self::FAILURE;
        }

        foreach (['Controllers', 'Models', 'Services', 'routes'] as $dir) {
            File::ensureDirectoryExists("{$basePath}/{$dir}");
        }

        $namespace = $siteCode
            ? 'App\\Sites\\'.Str::studly($siteCode).'\\Api\\'.$moduleStudly
            : 'App\\Api\\'.$moduleStudly;

        $controllerName = ($portal ? Str::studly($portal) : '').Str::studly($moduleStudly).'Controller';

        File::put("{$basePath}/Controllers/{$controllerName}.php", $this->controllerStub($namespace, $controllerName, $moduleStudly));
        File::put("{$basePath}/Services/{$moduleStudly}Service.php", $this->serviceStub($namespace, $moduleStudly));
        File::put("{$basePath}/routes/api.php", $this->routesStub($namespace, $controllerName, $moduleCode, $portal));

        $this->info("模組骨架已建立：{$basePath}");

        if ($siteCode) {
            // 見 README 6.9：子站客製化模組不進入共用的模組購買目錄——寫進
            // 客製化目錄本身就等於這個客戶已經買了，不需要 module.json，
            // 也不掃描／不登記進 modules 主表（那張表回答的是「產品線總共
            // 有哪些可以賣的模組」，跟單一客戶的客製化程式碼是兩件事）。
            $this->warn('這是子站客製化全新模組，不會自動掛進共用路由，執行期由 EnsureSiteContext 依站點載入，見 9.2。');
            $this->warn('客製化模組不登記進 modules 主表（不受模組購買機制管轄），見 6.9。');

            return self::SUCCESS;
        }

        $manifest = $this->askModuleManifest($moduleCode, $moduleStudly);
        File::put("{$basePath}/module.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->registerModule();

        return self::SUCCESS;
    }

    // 公版共用模組才需要 module.json（見 README 6.9：ModuleRegistryService
    // 只掃描 app/Api/*/module.json）。用互動問答取代靜態預設值，把整個開發
    // 流程（腳手架→依賴宣告→選單註冊）在建立當下就走完，不用工程師事後
    // 再手改 JSON。--no-interaction 時 ask/confirm/choice 會直接回傳這裡
    // 給的預設值，行為等同原本的靜態 stub。
    private function askModuleManifest(string $moduleCode, string $moduleStudly): array
    {
        $name = $this->ask('模組顯示名稱', $moduleStudly);
        $description = $this->ask('模組描述（一句話說明用途）', '');
        $isCore = $this->confirm('是否為基礎包模組（所有子站預設啟用，不需個別開通）？', false);
        $sort = (int) $this->ask('排序值（數字越小越前面）', 99);

        return [
            'code' => $moduleCode,
            'name' => $name,
            'description' => $description,
            'is_core' => $isCore,
            'sort' => $sort,
            'depends' => $this->askDepends($moduleCode),
            'menus' => $this->askMenus($moduleCode, $name),
        ];
    }

    // 依賴清單來源直接用既有模組的掃描結果（見 6.9），避免手動打字打錯代碼；
    // 掃描結果本身為空（第一個模組）或掃描失敗時就跳過，不擋住腳手架流程。
    private function askDepends(string $moduleCode): array
    {
        try {
            $existing = array_keys(app(ModuleRegistryService::class)->scanManifests());
        } catch (Throwable $e) {
            $this->warn("無法讀取現有模組清單（{$e->getMessage()}），略過依賴選擇，稍後可自行編輯 module.json。");

            return [];
        }

        $choices = array_values(array_diff($existing, [$moduleCode]));

        if ($choices === []) {
            return [];
        }

        if (! $this->confirm('是否依賴其他既有模組？', false)) {
            return [];
        }

        return $this->choice('選擇依賴的模組（可用逗號分隔多選）', $choices, null, null, true);
    }

    // 選單登記見 6.9：模組宣告的每一筆會在開通時自動 upsert 進該站 menus
    // 表，不用再手改 SiteBaselineSeeder。分組固定為現有兩個手動種子分組
    // （見 SiteBaselineSeeder::seedAdminMenu），這裡不開放自訂新分組。
    private function askMenus(string $moduleCode, string $defaultTitle): array
    {
        $menus = [];

        if (! $this->confirm('是否要為此模組新增後台選單項目？', true)) {
            return $menus;
        }

        do {
            $portal = $this->ask('選單所屬端口（portal_key）', 'admin');
            $key = $this->ask('選單代碼（key，同站需唯一）', $moduleCode);
            $title = $this->ask('選單標題', $defaultTitle);
            $subtitle = $this->ask('選單副標題（可留空）', '');
            $icon = $this->ask('選單圖示（Element Plus icon 名稱）', 'Menu');
            $path = $this->ask('前端路由路徑', "/{$moduleCode}");
            $permissionCode = $this->ask('對應權限代碼（permission_code）', "api.{$moduleCode}.index");
            $group = $this->choice('掛載到哪個分組？', ['group-account', 'group-system', '(不掛分類)'], 0);
            $sort = (int) $this->ask('排序值', 0);

            $menus[] = [
                'portal' => $portal,
                'key' => $key,
                'title' => $title,
                'subtitle' => $subtitle !== '' ? $subtitle : null,
                'icon' => $icon,
                'path' => $path,
                'permission_code' => $permissionCode,
                'group' => $group === '(不掛分類)' ? null : $group,
                'sort' => $sort,
            ];
        } while ($this->confirm('是否再新增一筆選單項目？', false));

        return $menus;
    }

    private function registerModule(): void
    {
        try {
            app(ModuleRegistryService::class)->syncToDatabase();
            $this->info('已依 module.json 同步到 modules 主表（預設 is_core=false，見 6.9，記得在主站後台開通給要用的子站）。');
        } catch (Throwable $e) {
            $this->warn("無法自動同步到 modules 主表（{$e->getMessage()}），請手動執行 php artisan module:sync，見 6.9。");
        }
    }

    private function controllerStub(string $namespace, string $controllerName, string $moduleStudly): string
    {
        return <<<PHP
        <?php

        namespace {$namespace}\Controllers;

        use App\Support\Http\Controllers\BaseApiController;
        use Illuminate\Http\Request;

        class {$controllerName} extends BaseApiController
        {
            public function index(Request \$request)
            {
                // TODO: \$this->validateInput(\$request, [...]) + 呼叫 Service。
                return \$this->success([]);
            }
        }

        PHP;
    }

    private function serviceStub(string $namespace, string $moduleStudly): string
    {
        return <<<PHP
        <?php

        namespace {$namespace}\Services;

        class {$moduleStudly}Service
        {
            // 業務邏輯放這裡，見 3.1「Web 遵循 MVC；API 遵循 MSC」。
        }

        PHP;
    }

    private function routesStub(string $namespace, string $controllerName, string $moduleCode, ?string $portal): string
    {
        $middleware = "module:{$moduleCode}";
        // 注意：heredoc/雙引號字串裡 `\{$var}`（反斜線緊接大括號插值）不會被
        // PHP 正確解析成插值，會原樣印出大括號，所以這裡先組好完整類別路徑
        // 字串再插入，不要在 use 語句裡直接寫 `{$namespace}\Controllers\{$controllerName}`。
        $controllerFqcn = $namespace.'\\Controllers\\'.$controllerName;

        return <<<PHP
        <?php

        use Illuminate\Support\Facades\Route;
        use {$controllerFqcn};

        // 見 README 6.9：模塊路由需掛載 module:{code} middleware。
        Route::middleware('{$middleware}')->prefix('{$moduleCode}')->group(function () {
            Route::get('/', [{$controllerName}::class, 'index']);
        });

        PHP;
    }
}
