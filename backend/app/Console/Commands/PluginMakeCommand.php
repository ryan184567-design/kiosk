<?php

/* *****************************************
File: PluginMakeCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 插件骨架腳本，見 README 18 第 6 點、6.10。
***************************************** */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// 插件骨架腳本，見 README 18 第 6 點、6.10。
//
// 注意：本檔案組字串一律用 `.` 串接，不要在 heredoc/雙引號字串裡寫
// `\{$var}`（反斜線緊接大括號插值）——PHP 不會把它解析成插值，會原樣印出
// 大括號（例如印出 `\{Order}` 而不是 `\Order`），是個容易忽略的坑。
class PluginMakeCommand extends Command
{
    protected $signature = 'plugin:make
        {function : 功能，例如 ad、sso、access_control}
        {brand : 品牌，例如 microsoft、okta}
        {version : 版本，例如 v2019、v1}';

    protected $description = '建立新插件骨架（見 README 6.10）';

    public function handle(): int
    {
        $function = strtolower($this->argument('function'));
        $brand = strtolower($this->argument('brand'));
        $version = strtolower($this->argument('version'));

        foreach ([$function, $brand, $version] as $part) {
            if (! preg_match('/^[a-z0-9_]+$/', $part)) {
                $this->error('function/brand/version 只能是小寫英數底線。');

                return self::FAILURE;
            }
        }

        $functionStudly = Str::studly($function);
        $brandStudly = Str::studly($brand);
        $versionStudly = strtoupper($version);
        $connectorName = $functionStudly.'Connector';

        $contractNamespace = 'App\\Plugins\\'.$functionStudly.'\\Contracts';
        $contractFqcn = $contractNamespace.'\\'.$connectorName.'Interface';
        $contractPath = app_path('Plugins/'.$functionStudly.'/Contracts/'.$connectorName.'Interface.php');

        if (! File::exists($contractPath)) {
            File::ensureDirectoryExists(dirname($contractPath));
            File::put($contractPath, $this->contractStub($contractNamespace, $connectorName));
            $this->info("已建立契約介面：{$contractPath}");
        }

        $implNamespace = 'App\\Plugins\\'.$functionStudly.'\\'.$brandStudly.'\\'.$versionStudly;
        $implFqcn = $implNamespace.'\\'.$connectorName;
        $implPath = app_path('Plugins/'.$functionStudly.'/'.$brandStudly.'/'.$versionStudly.'/'.$connectorName.'.php');

        if (File::exists($implPath)) {
            $this->error("插件已存在：{$implPath}");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($implPath));
        File::put($implPath, $this->connectorStub($implNamespace, $contractFqcn, $connectorName));

        $key = "{$brand}_{$version}";

        $this->info("已建立插件：{$implPath}");
        $this->warn('記得登記到 config/plugins.php（見 6.10.2，命名規則 {品牌}_{版本}）：');
        $this->line("    '{$function}' => ['{$key}' => \\".$implFqcn.'::class],');

        return self::SUCCESS;
    }

    private function contractStub(string $namespace, string $connectorName): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        // 統一介面，見 README 6.10.1：所有品牌/版本的實作都必須實作這個介面，
        // 呼叫端永遠依賴介面與 DTO，不依賴任何品牌專屬類別。
        interface {$connectorName}Interface
        {
            // TODO: 定義這個功能的標準輸入/輸出方法，例如：
            // public function authenticate(array \$credentials): array;

            public static function configSchema(): array;
        }

        PHP;
    }

    private function connectorStub(string $namespace, string $contractFqcn, string $connectorName): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use {$contractFqcn};

        // 見 README 6.10。內部若拋出廠商 SDK 例外，需攔截轉換為
        // PluginConnectorException（見 6.10.3），不可讓呼叫端接到廠商專屬例外。
        class {$connectorName} implements {$connectorName}Interface
        {
            public function __construct(private readonly array \$config) {}

            public static function configSchema(): array
            {
                // 比照 README 17.1 的 cfg_type/cfg_option 慣例，例如：
                // return ['host' => ['type' => 'string', 'required' => true]];
                return [];
            }
        }

        PHP;
    }
}
