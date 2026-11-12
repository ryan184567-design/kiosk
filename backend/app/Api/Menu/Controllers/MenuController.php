<?php

/* *****************************************
File: MenuController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單管理，見 README 7.3。
***************************************** */

namespace App\Api\Menu\Controllers;

use App\Api\Menu\Models\Menu;
use App\Api\Menu\Services\MenuAdminService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Permission\PermissionService;
use App\Support\Portal\Models\Portal;
use Illuminate\Http\Request;
use RuntimeException;

// 選單管理，見 README 7.3。跨端口規則與 RoleController 相同（見該檔開頭
// 註解），但多一層 7.3 明訂的例外：admin_only 端口（7.2）的選單管理只開放
// 給該端口的端口管理員或 is_system／is_delegation_root，不像 Role/User
// 管理那樣「持有對應權限碼的一般角色」也能操作——因為選單本身會決定其他
// 使用者看得到什麼功能入口，admin_only 端口對這件事的把關要更嚴格。
// MenuAdminService 用方法參數注入、不用建構子注入，理由見 RoleController
// 開頭註解與 README 9.2。
class MenuController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/menus
     * @api-tag Menu
     * @api-summary 列出選單（扁平清單，樹狀由前端依 parent_id 組裝）
     * @api-description
     * 回傳扁平清單，不是巢狀樹狀結構——前端自己依每筆的 `parent_id` 組裝
     * 出樹狀選單，後端不重複做這件事。不帶 `portal_id` 查詢參數時列出
     * 目前登入端口的選單；帶了且呼叫者有權管理該端口（自己就是該端口的
     * 端口管理員，或持有 `api.menu.index` 權限碼，或 `is_system`／
     * `is_delegation_root`），可以跨端口查詢其他端口的選單。
     * 這支只回傳選單「定義」本身（有哪些節點、標題、圖示、排序…），不做
     * 「目前登入者看不看得到這個節點」的過濾——那是給選單管理頁面編輯用的
     * 完整清單，一般使用者實際登入後看到的選單（依權限過濾）走另一支
     * （見 `GET /api/site/config` 的 `menu` 欄位）。分頁參數 `per_page=-1`
     * （預設）代表不分頁、回傳全部。
     * @api-portal *
     * @api-response-field data.items[].id int 選單ID
     * @api-response-field data.items[].parent_id int 父節點ID
     * @api-response-field data.items[].module_code string 所屬模塊代碼
     * @api-response-field data.items[].key string 選單識別碼
     * @api-response-field data.items[].title string 標題
     * @api-response-field data.items[].subtitle string 副標題
     * @api-response-field data.items[].icon string 圖示
     * @api-response-field data.items[].path string 前端路徑
     * @api-response-field data.items[].target string 開啟方式，_self=目前視窗、_blank=新視窗
     * @api-response-field data.items[].permission_code string 額外綁定的權限碼
     * @api-response-field data.items[].login_only bool 只要登入即可見，跳過權限碼判斷
     * @api-response-field data.items[].sort int 排序
     * @api-response-field data.items[].is_enabled bool 是否啟用
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function index(Request $request, MenuAdminService $menus)
    {
        $portalId = $this->resolveTargetPortalId($request, $request->integer('portal_id') ?: null);
        $this->authorizeMenuManagement($request, $portalId, 'api.menu.index');

        $portal = Portal::query()->findOrFail($portalId);

        $result = $this->paginateOrGet(
            $menus->queryForPortal($portal->portal_key),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['items'] = collect($result['items'])->map(fn (Menu $menu) => $this->present($menu))->all();

        return $this->success($result);
    }

    /**
     * @api-method GET
     * @api-path /api/menus/modules
     * @api-tag Menu
     * @api-summary 列出可選的模塊代碼（供選單表單下拉）
     * @api-description
     * 回傳 modules 主表中啟用中的模塊（見 6.9），供選單新增／編輯表單的
     * `module_code` 下拉使用——下拉只列出已登記且啟用的模塊，避免手打
     * 打錯字被 `MenuAdminService::assertModuleExists` 拒絕。權限沿用
     * `api.menu.index`（能看選單清單就需要這份輔助清單，不另外開權限碼）。
     * @api-portal *
     * @api-response-field data[].id int 模塊ID
     * @api-response-field data[].code string 模塊代碼，供 menu.module_code 使用
     * @api-response-field data[].name string 模塊名稱
     */
    public function modules(MenuAdminService $menus)
    {
        return $this->success(
            $menus->availableModules()->all()
        );
    }

    /**
     * @api-method POST
     * @api-path /api/menus
     * @api-tag Menu
     * @api-summary 建立選單節點
     * @api-description
     * 實際登入後這個節點會不會顯示給某個使用者，判斷邏輯是：把
     * `module_code` 對應頁面底下所有 Web 端權限碼（`permissions` 表裡
     * `domain=web` 且 `module_code` 相符的那些，來自 `permission:sync-web`
     * 掃描結果——同一頁面增/刪/改/查任一支路由只要有掛權限檢查就會自動
     * 進這份清單，不需要在選單上手動一一列舉動作）加上這裡的
     * `permission_code`（若有填）合併成一份清單，使用者只要持有清單中
     * **任一個**權限碼就能看到這個節點（OR 邏輯，不是要求同時持有全部）。
     * 若 `module_code` 對應頁面完全沒被 `permission:sync-web` 掃到任何權限碼、
     * 且 `permission_code` 也留空，這個節點會被視為「沒有任何可判斷的
     * 權限來源」而**永遠不顯示**，不是預設對所有人可見——這是刻意的保守
     * 設計，避免忘記綁定權限反而造成功能對所有人曝光。**例外**：`login_only`
     * 設為 `true` 時完全跳過上述權限碼判斷，只要登入這個端口就看得到（適合
     * 像「儀表板」這種每個人都該看到、不對應特定權限的節點），此時
     * `module_code`／`permission_code` 通常都留空。父節點的可見性則是
     * 「至少有一個子節點可見」，不需要自己也符合權限規則，`login_only`
     * 對分組節點無意義（分組本身不是頁面）。
     * 另外，模塊若未對目前子站開通（見 `modules`/`site_modules`），這個
     * 節點（含所有子節點）一律不顯示，即使使用者持有對應權限碼或
     * `login_only=true` 也一樣。
     * `module_code` 需對應 `modules` 主表已登記的模塊代碼，未登記的代碼
     * 會被拒絕建立。**選單最多兩層**：`parent_id` 指定的父節點本身若已經
     * 有父節點（已經是第二層），或這個節點自己底下已經有子節點卻想改掛
     * 到別的節點下，一律拒絕（見 `MenuAdminService` 的兩層限制判斷）。
     * @api-portal *
     * @api-request-field key string 選單識別碼（同端口內不可重複）
     * @api-request-field title string 標題
     * @api-request-field parent_id int 父節點ID，省略為頂層節點
     * @api-request-field module_code string 所屬模塊代碼，需為 modules 主表已登記的代碼
     * @api-request-field permission_code string 額外併入可見性判斷清單的權限碼，可省略（見上方 description 的 OR 邏輯）
     * @api-request-field login_only bool 只要登入即可見，跳過權限碼判斷，省略預設 false
     * @api-request-field subtitle string 副標題
     * @api-request-field icon string 圖示
     * @api-request-field path string 前端路徑
     * @api-request-field target string 開啟方式，_self=目前視窗（預設）、_blank=新視窗，省略為 _self
     * @api-request-field sort int 排序
     * @api-request-field is_enabled bool 是否啟用
     * @api-request-field portal_id int 要建立在哪個端口，省略則預設目前端口
     * @api-request-example
     * {"key": "users", "title": "使用者管理", "module_code": "user", "path": "/users", "sort": 0}
     * @api-response-field data.id int 選單ID
     * @api-response-field data.key string 選單識別碼
     */
    public function store(Request $request, MenuAdminService $menus)
    {
        $data = $this->validateInput($request, [
            'key' => 'required|string|max:100',
            'title' => 'required|string|max:100',
            'parent_id' => 'nullable|integer',
            'module_code' => 'nullable|string|max:100',
            'permission_code' => 'nullable|string|max:150',
            'login_only' => 'boolean',
            'subtitle' => 'nullable|string|max:150',
            'icon' => 'nullable|string|max:100',
            'path' => 'nullable|string|max:255',
            'target' => 'nullable|in:_self,_blank',
            'sort' => 'integer',
            'is_enabled' => 'boolean',
            'portal_id' => 'nullable|integer',
        ]);

        $portalId = $this->resolveTargetPortalId($request, $data['portal_id'] ?? null);
        $this->authorizeMenuManagement($request, $portalId, 'api.menu.store');

        $portal = Portal::query()->findOrFail($portalId);
        unset($data['portal_id']);
        $data['portal_key'] = $portal->portal_key;

        try {
            $menu = $menus->create($this->currentUser($request), $data, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success($this->present($menu), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/menus/{menu}
     * @api-tag Menu
     * @api-summary 更新選單節點
     * @api-description
     * 欄位意義與可見性判斷邏輯（`module_code` 對應頁面的 Web 權限碼 OR
     * `permission_code`，兩者留空則永遠不顯示；`login_only=true` 時跳過
     * 這個判斷，只要登入即可見）與 `POST /api/menus` 完全相同。
     * @api-portal *
     * @api-request-field key string 選單識別碼
     * @api-request-field title string 標題
     * @api-request-field parent_id int 父節點ID
     * @api-request-field module_code string 所屬模塊代碼
     * @api-request-field permission_code string 額外併入可見性判斷清單的權限碼
     * @api-request-field login_only bool 只要登入即可見，跳過權限碼判斷
     * @api-request-field subtitle string 副標題
     * @api-request-field icon string 圖示
     * @api-request-field path string 前端路徑
     * @api-request-field target string 開啟方式，_self=目前視窗、_blank=新視窗
     * @api-request-field sort int 排序
     * @api-request-field is_enabled bool 是否啟用
     * @api-request-example
     * {"key": "users", "title": "使用者管理", "sort": 1, "is_enabled": true}
     * @api-response-field data.id int 選單ID
     * @api-response-field data.key string 選單識別碼
     */
    public function update(Request $request, int $menu, MenuAdminService $menus)
    {
        $menuModel = $this->findAuthorizedMenu($request, $menu, 'api.menu.update');

        $data = $this->validateInput($request, [
            'key' => 'required|string|max:100',
            'title' => 'required|string|max:100',
            'parent_id' => 'nullable|integer',
            'module_code' => 'nullable|string|max:100',
            'permission_code' => 'nullable|string|max:150',
            'login_only' => 'boolean',
            'subtitle' => 'nullable|string|max:150',
            'icon' => 'nullable|string|max:100',
            'path' => 'nullable|string|max:255',
            'target' => 'nullable|in:_self,_blank',
            'sort' => 'integer',
            'is_enabled' => 'boolean',
        ]);

        try {
            $menuModel = $menus->update($this->currentUser($request), $menuModel, $data, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success($this->present($menuModel));
    }

    /**
     * @api-method DELETE
     * @api-path /api/menus/{menu}
     * @api-tag Menu
     * @api-summary 刪除選單節點
     * @api-description
     * 仍有子選單時會拒絕刪除（見 `MenuAdminService::delete`），需先刪除或
     * 改掛子選單。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroy(Request $request, int $menu, MenuAdminService $menus)
    {
        $menuModel = $this->findAuthorizedMenu($request, $menu, 'api.menu.destroy');

        try {
            $menus->delete($this->currentUser($request), $menuModel, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }

    /**
     * @api-method PUT
     * @api-path /api/menus/reorder
     * @api-tag Menu
     * @api-summary 拖曳排序選單（批量更新 parent_id／sort）
     * @api-description
     * 選單管理頁面支援拖曳排序，前端只需要送「這次操作有變動的節點」，
     * 每筆帶 `id`/`parent_id`/`sort`，不用每次都攤平整棵樹送上來。包在
     * 同一個 DB 交易內：任一筆違反兩層限制或帶到不屬於此端口的節點，
     * 整批都不會寫入（見 `MenuAdminService::reorder`）。
     * @api-portal *
     * @api-request-field items[].id int 選單ID
     * @api-request-field items[].parent_id int 新的父節點ID，省略／null 為頂層節點
     * @api-request-field items[].sort int 新的排序值
     * @api-request-field portal_id int 要排序哪個端口的選單，省略則預設目前端口
     * @api-request-example
     * {"items": [{"id": 3, "parent_id": 2, "sort": 1}]}
     * @api-response-empty
     */
    public function reorder(Request $request, MenuAdminService $menus)
    {
        $data = $this->validateInput($request, [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.parent_id' => 'nullable|integer',
            'items.*.sort' => 'integer',
            'portal_id' => 'nullable|integer',
        ]);

        $portalId = $this->resolveTargetPortalId($request, $data['portal_id'] ?? null);
        $this->authorizeMenuManagement($request, $portalId, 'api.menu.update');

        $portal = Portal::query()->findOrFail($portalId);

        try {
            $menus->reorder($this->currentUser($request), $portal->portal_key, $data['items'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/menus/portals
     * @api-tag Menu
     * @api-summary 列出目前使用者可管理選單的端口（供選單管理頁面切換端口用）
     * @api-description
     * 只回傳呼叫者實際能管理選單的端口——`is_system`／`is_delegation_root`
     * 回全部已啟用端口；一般使用者只回自己是端口管理員、或在該端口持有
     * `api.menu.index` 權限碼的端口，避免選單頁面切出一個選了就一定 403
     * 的端口選項。
     * @api-portal *
     * @api-response-field data[].id int 端口ID
     * @api-response-field data[].portal_key string 端口代碼
     * @api-response-field data[].name string 端口名稱
     */
    public function portals(Request $request)
    {
        $user = $this->currentUser($request);
        $permissions = app(PermissionService::class);
        $bypass = $permissions->bypassesDelegationCeiling($user);

        $portals = Portal::query()->where('is_enabled', true)->orderBy('id')->get()->filter(
            fn (Portal $portal) => $bypass
                || $permissions->isPortalAdmin($user, $portal->id)
                || $permissions->can($user, $portal->id, 'api.menu.index')
        )->values();

        return $this->success($portals->map(fn (Portal $portal) => [
            'id' => $portal->id,
            'portal_key' => $portal->portal_key,
            'name' => $portal->name,
        ])->all());
    }

    // 見 README 開發約定第 15 點：不用隱式路由模型綁定，理由同 RoleController。
    private function findAuthorizedMenu(Request $request, int $menuId, string $code): Menu
    {
        $menu = Menu::query()->findOrFail($menuId);
        $portal = Portal::query()->where('portal_key', $menu->portal_key)->firstOrFail();
        $portalId = $this->resolveTargetPortalId($request, $portal->id);
        $this->authorizeMenuManagement($request, $portalId, $code);

        return $menu;
    }

    // 見 7.3：admin_only 端口的選單管理只開放端口管理員／is_system／
    // is_delegation_root，一般持有對應權限碼的角色不算——因為選單決定其他
    // 使用者看得到什麼功能入口，admin_only 端口對此把關要更嚴格，不能只靠
    // 一般的權限碼授權機制。非 admin_only 端口則沿用 RoleController 那套
    // 一般規則（authorizeOnPortal：持有權限碼，或 ceiling bypass）。
    private function authorizeMenuManagement(Request $request, int $portalId, string $code): void
    {
        $portal = Portal::query()->findOrFail($portalId);

        if (! $portal->admin_only) {
            $this->authorizeOnPortal($request, $portalId, $code);

            return;
        }

        $user = $this->currentUser($request);
        $permissions = app(PermissionService::class);

        $allowed = $permissions->bypassesDelegationCeiling($user)
            || $permissions->isPortalAdmin($user, $portalId);

        if (! $allowed) {
            abort(403, __('api.error_catalog.permission_denied'));
        }
    }

    private function present(Menu $menu): array
    {
        return [
            'id' => $menu->id,
            'parent_id' => $menu->parent_id,
            'module_code' => $menu->module_code,
            'key' => $menu->key,
            'title' => $menu->title,
            'subtitle' => $menu->subtitle,
            'icon' => $menu->icon,
            'path' => $menu->path,
            'target' => $menu->target,
            'permission_code' => $menu->permission_code,
            'login_only' => $menu->login_only,
            'sort' => $menu->sort,
            'is_enabled' => $menu->is_enabled,
        ];
    }
}
