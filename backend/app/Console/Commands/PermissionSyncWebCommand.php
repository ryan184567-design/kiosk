<?php

/* *****************************************
File: PermissionSyncWebCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 同步 Web 權限模板，見 README 18.2 第 8-9 項、18.2.1。
***************************************** */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SelectsSite;
use App\Support\Permission\Models\Permission;
use App\Api\Role\Models\Role;
use App\Api\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

// 同步 Web 權限模板，見 README 18.2 第 8-9 項、18.2.1。掃描 app/Web 底下
// 掛載 permission.web 的路由，不像 permission:sync-api 靠 docblock 標註，
// 直接讀 permission.web:{code} middleware 參數本身就是權限碼。
class PermissionSyncWebCommand extends Command
{
    use SelectsSite;

    protected $signature = 'permission:sync-web
        {--site= : 要同步到哪個子站，見 --site={site_id}}
        {--assign-admin= : 把掃描到的權限碼併入指定帳號目前的角色}
        {--include-unprotected : 連未掛 permission.web 的路由也同步（不建議常態使用）}';

    protected $description = '同步 Web 權限模板（見 README 18.2 第 8-9 項）';

    public function handle(): int
    {
        if (! $this->resolveSiteOrFail()) {
            return self::FAILURE;
        }

        $config = config('webbase.permission.web_sync');
        $includeUnprotected = $this->option('include-unprotected') || $config['include_unprotected_routes'];
        $synced = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->isWebModuleRoute($route)) {
                continue;
            }

            if ($this->isExcluded($route, $config)) {
                continue;
            }

            $code = $this->extractPermissionCode($route);

            if (! $code) {
                if (! $includeUnprotected) {
                    continue;
                }

                $code = $this->deriveCodeFromRouteName($route);
            }

            if (! $code) {
                continue;
            }

            $this->syncOne($code, $route);
            $synced[] = $code;
            $this->line("同步：{$code}");
        }

        $this->info('共同步 '.count($synced).' 筆權限。');

        if ($assignTo = $this->option('assign-admin')) {
            $this->assignTo($assignTo, $synced);
        }

        return self::SUCCESS;
    }

    private function isWebModuleRoute(Route $route): bool
    {
        // getActionName() 回傳帶前導反斜線的 FQCN（例如 \App\Web\...），
        // 這裡先去掉才能正確比對，不然永遠比對不到。
        $action = ltrim($route->getActionName(), '\\');

        return Str::startsWith($action, 'App\\Web\\');
    }

    private function isExcluded(Route $route, array $config): bool
    {
        foreach ($config['exclude_paths'] ?? [] as $pattern) {
            if (Str::is(ltrim($pattern, '/'), $route->uri())) {
                return true;
            }
        }

        foreach ($config['exclude_route_names'] ?? [] as $pattern) {
            if ($route->getName() && Str::is($pattern, $route->getName())) {
                return true;
            }
        }

        return false;
    }

    private function extractPermissionCode(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (Str::startsWith($middleware, 'permission.web:')) {
                return Str::after($middleware, 'permission.web:');
            }
        }

        return null;
    }

    // 見 18.2.1 第 3 點：web_action 只回傳 entry/view/create/update/delete，不含 *。
    private function deriveCodeFromRouteName(Route $route): ?string
    {
        $name = $route->getName();

        if (! $name || substr_count($name, '.') < 2) {
            return null;
        }

        [$portal, $module, $action] = array_slice(explode('.', $name), 0, 3);

        if (! in_array($action, ['entry', 'view', 'create', 'update', 'delete'], true)) {
            return null;
        }

        return "web.{$module}.{$action}";
    }

    private function syncOne(string $code, Route $route): void
    {
        $parts = explode('.', $code);
        $moduleCode = $parts[1] ?? 'unknown';

        Permission::query()->updateOrCreate(
            ['code' => $code],
            [
                'module_code' => $moduleCode,
                'domain' => 'web',
                'method' => implode('|', $route->methods()),
                'path' => '/'.$route->uri(),
                'portal_keys' => [],
                'summary' => $route->getName(),
            ]
        );
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

        $this->info('已把 '.count($codes)." 筆權限併入角色「{$role->name}」（{$username} 目前使用的角色）。");
    }
}
