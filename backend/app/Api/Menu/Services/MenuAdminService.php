<?php

/* *****************************************
File: MenuAdminService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單管理業務邏輯，見 README 7.3。
***************************************** */

namespace App\Api\Menu\Services;

use App\Api\Menu\Models\Menu;
use App\Api\User\Models\User;
use App\Hq\SiteModule\Models\Module;
use App\Support\Audit\Models\AuditLog;
use App\Support\Hq\HqPresence;
use App\Support\Menu\MenuCacheVersion;
use App\Support\Permission\Models\Permission;
use App\Support\Site\SiteContext;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 選單管理業務邏輯，見 README 7.3。Controller 只做驗證/轉發（4 節第 3 點）。
class MenuAdminService
{
    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly MenuCacheVersion $cacheVersion,
    ) {}

    // 回傳未執行的 Builder，交由 Controller 用共用的 PaginationHelper
    // 決定要不要分頁（見開發約定第 9 點）。
    public function queryForPortal(string $portalKey): Builder
    {
        return Menu::query()->where('portal_key', $portalKey)->orderBy('parent_id')->orderBy('sort');
    }

    // 供選單新增／編輯表單的「模塊代碼」下拉使用（MenuController::modules）。
    // 跟 assertModuleExists 用同一張表、同一個判斷基準（is_enabled），前端
    // 只會選到已登記且啟用的代碼，不會手打打錯字被建立/更新流程拒絕。
    //
    // 單站部署包沒有 modules 主表（見 HqPresence），回傳空集合；選單表單的
    // 模塊下拉本來就可以留空（`module_code` 是選填，見 MenuController 的
    // @api-request-field），所以空清單是合理降級，不會擋住選單管理功能。
    public function availableModules(): Collection
    {
        if (! HqPresence::moduleRegistryAvailable()) {
            return collect();
        }

        return Module::query()
            ->where('is_enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['code', 'name'])
            ->map(fn (Module $module) => ['code' => $module->code, 'name' => $module->name]);
    }

    public function create(User $actor, array $data, ?string $ip = null): Menu
    {
        $this->assertModuleExists($data['module_code'] ?? null);
        $this->assertPermissionCodeExists($data['permission_code'] ?? null);
        $this->assertParentSamePortal($data['portal_key'], $data['parent_id'] ?? null);
        $this->assertKeyAvailable($data['portal_key'], $data['key']);

        // sort/is_enabled 未帶入時交給資料庫欄位預設值（0／true），create()
        // 後的記憶體物件不會自動反映資料庫算出的預設值，refresh() 一次拉回
        // 真正存進去的值，避免回傳給呼叫端的資料跟資料庫實際狀態不一致。
        $menu = Menu::query()->create($data);
        $menu->refresh();

        $this->audit($actor, 'menu.create', $menu, $ip);
        $this->cacheVersion->bump($this->siteContext->siteId);

        return $menu;
    }

    public function update(User $actor, Menu $menu, array $data, ?string $ip = null): Menu
    {
        $this->assertModuleExists($data['module_code'] ?? null);
        $this->assertPermissionCodeExists($data['permission_code'] ?? null);
        $this->assertParentSamePortal($menu->portal_key, $data['parent_id'] ?? null, $menu->id);
        $this->assertNoChildrenWhenAssigningParent($menu, $data['parent_id'] ?? null);

        if (($data['key'] ?? $menu->key) !== $menu->key) {
            $this->assertKeyAvailable($menu->portal_key, $data['key']);
        }

        $menu->update($data);

        $this->audit($actor, 'menu.update', $menu, $ip);
        $this->cacheVersion->bump($this->siteContext->siteId);

        return $menu;
    }

    // 見 7.3：子選單存在時拒絕刪除父節點，不靠資料庫層 cascade 靜默處理
    // （軟刪除也不會觸發 FK cascade），避免留下「父節點消失、子節點還在」
    // 這種資料不一致但沒有任何錯誤提示的情況。
    public function delete(User $actor, Menu $menu, ?string $ip = null): void
    {
        if (Menu::query()->where('parent_id', $menu->id)->exists()) {
            throw new RuntimeException(__('api.menu.has_children'));
        }

        $this->audit($actor, 'menu.delete', $menu, $ip);
        $menu->delete();
        $this->cacheVersion->bump($this->siteContext->siteId);
    }

    // 拖曳排序：前端只需要送「有變動的節點」（見 MenuController::reorder），
    // 不用每次都攤平整棵樹送上來。驗證基底是這個端口目前資料庫裡的完整
    // 選單清單，沒被送上來的節點沿用原本的 parent_id；兩層限制一律以「這次
    // 操作後」的最終狀態為準（送上來的用送的、沒送的用原值），全部驗證
    // 通過才包在同一個交易內寫入，避免拖到一半失敗留下部分排序錯亂的狀態。
    public function reorder(User $actor, string $portalKey, array $items, ?string $ip = null): void
    {
        $submittedById = collect($items)->keyBy('id');
        $allMenus = Menu::query()->where('portal_key', $portalKey)->get()->keyBy('id');

        foreach ($submittedById->keys() as $id) {
            if (! $allMenus->has($id)) {
                throw new RuntimeException(__('api.menu.reorder_invalid_items'));
            }
        }

        foreach ($items as $item) {
            $newParentId = $item['parent_id'] ?? null;

            if ($newParentId === $item['id']) {
                throw new RuntimeException(__('api.menu.parent_cannot_be_self'));
            }

            if ($newParentId !== null && ! $allMenus->has($newParentId)) {
                throw new RuntimeException(__('api.menu.parent_invalid'));
            }
        }

        // 這次操作後每個節點最終的 parent_id：有送的用送的，沒送的維持
        // 資料庫原值。單一不變量涵蓋兩層限制的兩個方向——一個節點不能
        // 同時「自己有父節點」又「是別人的父節點」，不管是這次送上來的
        // 節點、還是資料庫裡沒異動過的既有節點，一律適用。
        $finalParentOf = $allMenus->map(
            fn (Menu $m) => $submittedById->has($m->id) ? ($submittedById[$m->id]['parent_id'] ?? null) : $m->parent_id
        );

        $parentIds = array_unique(array_filter($finalParentOf->values()->all(), fn ($id) => $id !== null));

        foreach ($parentIds as $parentId) {
            if (($finalParentOf[$parentId] ?? null) !== null) {
                throw new RuntimeException(__('api.menu.max_depth_exceeded'));
            }
        }

        DB::connection('site_current')->transaction(function () use ($items, $allMenus) {
            foreach ($items as $item) {
                $allMenus[$item['id']]->update([
                    'parent_id' => $item['parent_id'] ?? null,
                    'sort' => $item['sort'] ?? 0,
                ]);
            }
        });

        $this->audit($actor, 'menu.reorder', $allMenus->first() ?? new Menu(['portal_key' => $portalKey]), $ip);
        $this->cacheVersion->bump($this->siteContext->siteId);
    }

    // 見 15 節第 3、5 點：選單決定其他使用者看得到什麼功能入口，變更／刪改
    // 一律寫入該子站自己的 audit_logs。
    private function audit(User $actor, string $action, Menu $menu, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => "menu:{$menu->id}",
            'context' => ['portal_key' => $menu->portal_key, 'key' => $menu->key, 'title' => $menu->title],
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    private function assertModuleExists(?string $moduleCode): void
    {
        if (! $moduleCode) {
            return;
        }

        // 單站部署包沒有 modules 主表（見 HqPresence）：無法核對登記清單，
        // 直接放行，避免選單管理在單站環境完全不能用。
        if (! HqPresence::moduleRegistryAvailable()) {
            return;
        }

        if (! Module::query()->where('code', $moduleCode)->exists()) {
            throw new RuntimeException(__('api.menu.module_not_found', ['code' => $moduleCode]));
        }
    }

    private function assertPermissionCodeExists(?string $code): void
    {
        if (! $code) {
            return;
        }

        $exists = str_ends_with($code, '.*')
            ? Permission::query()->where('code', 'like', substr($code, 0, -1).'%')->exists()
            : Permission::query()->where('code', $code)->exists();

        if (! $exists) {
            throw new RuntimeException(__('api.menu.permission_code_not_found', ['code' => $code]));
        }
    }

    private function assertParentSamePortal(string $portalKey, ?int $parentId, ?int $selfId = null): void
    {
        if (! $parentId) {
            return;
        }

        if ($parentId === $selfId) {
            throw new RuntimeException(__('api.menu.parent_cannot_be_self'));
        }

        $parent = Menu::query()->find($parentId);

        if (! $parent || $parent->portal_key !== $portalKey) {
            throw new RuntimeException(__('api.menu.parent_invalid'));
        }

        // 見 7.3 兩層限制：父節點自己也掛在別的節點底下（已經是第二層），
        // 這個節點再掛上去就會變成第三層，一律拒絕。
        if ($parent->parent_id !== null) {
            throw new RuntimeException(__('api.menu.max_depth_exceeded'));
        }
    }

    // 見 7.3 兩層限制的另一半：一個節點自己底下已經有子節點（它是第一層），
    // 這時候不能再把它自己也掛到別的節點底下變成第二層，否則它原本的子
    // 節點會被連帶擠到第三層。
    private function assertNoChildrenWhenAssigningParent(Menu $menu, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        if (Menu::query()->where('parent_id', $menu->id)->exists()) {
            throw new RuntimeException(__('api.menu.max_depth_exceeded'));
        }
    }

    // key 沒有資料庫層唯一鍵（見 migration），前端多半拿它當穩定識別碼，
    // 這裡在服務層擋重複，理由同 RoleService 的角色名稱檢查。
    private function assertKeyAvailable(string $portalKey, string $key): void
    {
        if (Menu::query()->where('portal_key', $portalKey)->where('key', $key)->exists()) {
            throw new RuntimeException(__('api.menu.key_exists', ['key' => $key]));
        }
    }
}
