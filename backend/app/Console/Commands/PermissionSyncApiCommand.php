<?php

/* *****************************************
File: PermissionSyncApiCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 掃描 app/Api 下所有 Controller 的 @api-xxxx 標註，同步到 permissions 目錄， 見 README 7.4、12.7 第 3 點「掃描範圍: app/Api 下所有 Controller 註解」。
***************************************** */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SelectsSite;
use App\Hq\SiteModule\Models\Module;
use App\Support\Permission\Models\Permission;
use App\Api\Role\Models\Role;
use App\Api\User\Models\User;
use App\Support\Api\Doc\ApiAnnotationParser;
use App\Support\Api\Doc\ApiAnnotationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;

// 掃描 app/Api 下所有 Controller 的 @api-xxxx 標註，同步到 permissions 目錄，
// 見 README 7.4、12.7 第 3 點「掃描範圍: app/Api 下所有 Controller 註解」。
class PermissionSyncApiCommand extends Command
{
    use SelectsSite;

    protected $signature = 'permission:sync-api
        {--site= : 要同步到哪個子站，見 --site={site_id}}
        {--assign-admin= : 把掃描到的權限碼併入指定帳號目前的角色}';

    protected $description = '同步內部 API 權限（見 README 18.2 第 7 項）';

    public function handle(): int
    {
        if (! $this->resolveSiteOrFail()) {
            return self::FAILURE;
        }

        $parser = app(ApiAnnotationParser::class);
        $validator = app(ApiAnnotationValidator::class);
        $files = glob(app_path('Api/*/Controllers/*.php'));
        $synced = [];

        foreach ($files as $file) {
            $moduleStudly = $this->extractModuleStudly($file);
            $moduleCode = Str::snake($moduleStudly);
            $class = 'App\\Api\\'.$moduleStudly.'\\Controllers\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            if (! Module::query()->where('code', $moduleCode)->exists()) {
                // 見 6.9 第 4 點：不可靜默略過。
                $this->error("模塊未登記於 modules 主表，中斷：{$moduleCode}（{$file}）");

                return self::FAILURE;
            }

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->class !== $class) {
                    continue;
                }

                $parsed = $parser->parseMethod($method);

                if (! $parsed || empty($parsed['method']) || empty($parsed['path'])) {
                    continue;
                }

                try {
                    $validator->validate($parsed, $parsed['method'], "{$class}::{$method->getName()}");
                    $code = $this->syncOne($parser, $parsed, $moduleCode, $method->getName());
                    $synced[] = $code;
                    $this->line("同步：{$code}");
                } catch (RuntimeException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
            }
        }

        $this->info('共同步 '.count($synced).' 筆權限。');

        if ($assignTo = $this->option('assign-admin')) {
            $this->assignTo($assignTo, $synced);
        }

        return self::SUCCESS;
    }

    private function extractModuleStudly(string $file): string
    {
        // .../app/Api/{ModuleStudly}/Controllers/X.php
        $parts = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $file));
        $idx = array_search('Api', $parts, true);

        return $parts[$idx + 1];
    }

    // 標註格式（portal 是否存在、型別是否合法）已由 ApiAnnotationValidator
    // 在呼叫端統一驗證過，這裡只做實際同步，見 12.6。
    private function syncOne(ApiAnnotationParser $parser, array $parsed, string $moduleCode, string $methodName): string
    {
        $portals = $parser->portals($parsed);
        $code = "api.{$moduleCode}.{$methodName}";

        Permission::query()->updateOrCreate(
            ['code' => $code],
            [
                'module_code' => $moduleCode,
                'domain' => 'api',
                'method' => $parsed['method'],
                'path' => $parsed['path'],
                'portal_keys' => $portals,
                'summary' => $parsed['summary'] ?? null,
            ]
        );

        return $code;
    }

    private function assignTo(string $username, array $codes): void
    {
        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->warn("--assign-admin 找不到帳號：{$username}");

            return;
        }

        $access = $user->portalAccess()->whereNotNull('role_id')->first();

        if (! $access) {
            $this->warn("{$username} 沒有任何帶 role_id 的端口存取紀錄（可能是端口管理員，已擁有端口全部權限），略過。");

            return;
        }

        $role = Role::query()->find($access->role_id);
        $merged = array_values(array_unique(array_merge($role->permission_codes ?? [], $codes)));
        $role->update(['permission_codes' => $merged]);

        $this->info("已把 ".count($codes)." 筆權限併入角色「{$role->name}」（{$username} 目前使用的角色）。");
    }
}
