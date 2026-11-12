<?php

/* *****************************************
File: MenuService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 7.3 MenuService::getMenuForUser。
***************************************** */

namespace App\Support\Menu;

use App\Api\Menu\Models\Menu;
use App\Support\Permission\Models\Permission;
use App\Api\User\Models\User;
use App\Support\Module\ModuleAccessService;
use App\Support\Permission\PermissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// 見 README 7.3 MenuService::getMenuForUser。可見性判斷交給
// PermissionService::canAny 處理（已內含 is_system/is_portal_admin 短路，
// 這裡不重複判斷一次），只要有該頁面涉及的任一 CRUD API 權限就顯示。
class MenuService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ModuleAccessService $moduleAccess,
        private readonly MenuCacheVersion $cacheVersion,
    ) {}

    public function getMenuForUser(User $user, int $portalId, ?int $roleId, string $portalKey, int $siteId): array
    {
        $version = $this->cacheVersion->current($siteId);
        $cacheKey = "menu:site:{$siteId}:v:{$version}:user:{$user->id}:portal:{$portalId}:role:".($roleId ?? '0');

        return Cache::remember($cacheKey, now()->addHour(), function () use ($user, $portalId, $portalKey, $siteId) {
            $flat = Menu::query()
                ->where('portal_key', $portalKey)
                ->where('is_enabled', true)
                ->orderBy('sort')
                ->get();

            $byParent = $flat->groupBy('parent_id');
            $webPermissionsByModule = $this->loadWebPermissionsByModule($flat);

            return $this->buildVisible(null, $byParent, $user, $portalId, $siteId, $webPermissionsByModule);
        });
    }

    // 頁面（module_code）涉及的所有 web 權限碼，見 permission:sync-web
    // （18.2 第 8-9 項）——同一頁面底下增/刪/改/查任一支 API 只要掃過就會
    // 進這份清單，不需要在選單上手動一一列舉動作。
    private function loadWebPermissionsByModule(Collection $menus): Collection
    {
        $moduleCodes = $menus->pluck('module_code')->filter()->unique()->values();

        if ($moduleCodes->isEmpty()) {
            return collect();
        }

        return Permission::query()
            ->where('domain', 'web')
            ->whereIn('module_code', $moduleCodes)
            ->get()
            ->groupBy('module_code')
            ->map(fn (Collection $group) => $group->pluck('code')->all());
    }

    private function buildVisible(?int $parentId, Collection $byParent, User $user, int $portalId, int $siteId, Collection $webPermissionsByModule): array
    {
        $nodes = [];

        foreach ($byParent->get($parentId, collect()) as $menu) {
            // 未開通則整支（含子選單）不顯示，即使有權限也不例外，見 7.3 第 2 點。
            if ($menu->module_code && ! $this->moduleAccess->isEnabledForSite($menu->module_code, $siteId)) {
                continue;
            }

            $children = $this->buildVisible($menu->id, $byParent, $user, $portalId, $siteId, $webPermissionsByModule);
            $isLeaf = ! $byParent->has($menu->id);
            $requiredCodes = $this->requiredCodes($menu, $webPermissionsByModule);

            if ($isLeaf) {
                // login_only=true 的葉節點（例如儀表板）只要通過上面的模塊
                // 開通檢查就一定顯示，跳過權限碼判斷——適合「每個人都該看到、
                // 不對應特定權限」的節點，見 MenuController::store 說明。
                // 其餘葉節點沒有任何可判斷的權限來源（頁面 module_code 掃出的
                // API、或明確綁定的 permission_code）一律不顯示，避免忘記綁定
                // 造成越權外洩；有來源時，只要該頁面增刪改查任一權限就能看到
                // 本選單。
                if (! $menu->login_only && ($requiredCodes === [] || ! $this->permissions->canAny($user, $portalId, $requiredCodes))) {
                    continue;
                }
            } else {
                if ($requiredCodes !== [] && ! $this->permissions->canAny($user, $portalId, $requiredCodes)) {
                    continue;
                }

                // 父節點可見性 = 是否至少有一個子節點可見（子選單有任何權限即可見）。
                if ($children === []) {
                    continue;
                }
            }

            $nodes[] = [
                'key' => $menu->key,
                'title' => $menu->title,
                'subtitle' => $menu->subtitle,
                'icon' => $menu->icon,
                'path' => $menu->path,
                'target' => $menu->target,
                'children' => $children,
            ];
        }

        return $nodes;
    }

    private function requiredCodes(Menu $menu, Collection $webPermissionsByModule): array
    {
        $codes = $menu->module_code ? ($webPermissionsByModule->get($menu->module_code) ?? []) : [];

        if ($menu->permission_code) {
            $codes[] = $menu->permission_code;
        }

        return array_values(array_unique($codes));
    }
}
