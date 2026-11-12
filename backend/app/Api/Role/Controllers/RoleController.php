<?php

/* *****************************************
File: RoleController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色管理，見 README 7.1、7.2、7.4。
***************************************** */

namespace App\Api\Role\Controllers;

use App\Api\Role\Models\Role;
use App\Api\Role\Services\RoleService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Portal\Models\Portal;
use Illuminate\Http\Request;
use RuntimeException;

// 角色管理，見 README 7.1、7.2、7.4。預設只作用在目前登入 session 所屬的
// 端口；帶 portal_id 且呼叫者是該端口的端口管理員、或 is_system／
// is_delegation_root，才能跨到別的端口（見 BaseApiController::resolveTargetPortalId
// 開頭的完整說明——這是為了「有些端口本來就沒有自己的端口管理員，得靠
// 後台統一管理」這個實際情境，不是只開放給超級管理員）。
//
// 見 README 9.2：RoleService 刻意用「方法參數注入」而不是「建構子注入」——
// Laravel 的 Router 在 gatherMiddleware() 階段會為了檢查 Controller 是否有
// 宣告自己的 middleware（Illuminate\Routing\Controller::getMiddleware()，
// 所有 Controller 都繼承得到，永遠 method_exists() 成立）而提前完整建構一次
// Controller 實例，這個時間點在所有 route middleware（含 site.context）
// 執行之前，若 RoleService 是建構子依賴，會在 site override resolver
// 還沒綁定覆寫類別前就被提前解析、鎖死成公版類別，覆寫機制形同虛設且
// 不會有任何錯誤提示。方法參數則是在 Controller 方法真正被呼叫時
// （site.context 早就執行完畢）才由 Laravel 重新解析，能正確吃到覆寫綁定，
// 已實測驗證兩種寫法的差異（見 SiteOverrideResolver）。
class RoleController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/roles
     * @api-tag Role
     * @api-summary 列出角色
     * @api-description
     * 不帶 `portal_id` 查詢參數時列出目前登入端口的角色。帶了 `portal_id`
     * 且它跟目前登入端口不同時，會檢查呼叫者是否有權跨端口管理：只有
     * 「呼叫者是系統管理員（`is_delegation_root`）／超級管理員
     * （`is_system`）」，或「呼叫者本身就是這個目標端口的端口管理員
     * （`user_portal_access.is_portal_admin=true`，不論呼叫者目前登入在
     * 哪個端口）」這兩種情況才允許查詢別的端口，其餘一律回 403——這是
     * 特意為「有些端口本身沒有自己的端口管理員，需要靠系統管理員統一
     * 管理」這個情境設計的，不是只開放給超級管理員。
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].id int 角色ID
     * @api-response-field data.items[].name string 角色名稱
     * @api-response-field data.items[].permission_codes[] string 權限碼清單
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function index(Request $request, RoleService $roles)
    {
        $portalId = $this->resolveTargetPortalId($request, $request->integer('portal_id') ?: null);
        $this->authorizeOnPortal($request, $portalId, 'api.role.index');

        $result = $this->paginateOrGet(
            $roles->queryForPortal($portalId),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['items'] = collect($result['items'])->map(fn (Role $role) => $this->present($role))->all();

        return $this->success($result);
    }

    /**
     * @api-method GET
     * @api-path /api/roles/permission-catalog
     * @api-tag Role
     * @api-summary 取得權限碼目錄（供角色編輯頁面選單使用）
     * @api-description
     * `permissions` 表由 `permission:sync-api`（掃描 Controller 上的
     * `@api-xxx` 標註）與 `permission:sync-web`（掃描 Web 頁面路由）自動
     * 產生，涵蓋系統裡全部已知的權限碼，不需要工程師手動維護清單。
     * 回傳內容已依「目前登入 session 所屬端口」過濾：`domain=api` 的權限碼
     * 只保留標註 `@api-portal *` 或明確包含此端口的項目，跟
     * `permission:audit-ceiling` 判斷「跨端口洩漏」用同一套規則；
     * `domain=web` 的權限碼目前沒有可靠的端口關聯資料（見
     * `permission:sync-web` 說明），一律不過濾、全部保留。
     * 前端拿到後依 `module_code` 分組、`code` 最後一段推斷增/刪/改/查，
     * 做成按「端口—模塊—接口」三層排列的勾選樹給角色編輯頁面使用
     * （勾選結果就是 `POST /api/roles`／`PUT /api/roles/{role}` 的
     * `permission_codes`；父節點勾選＝該模塊或整個端口全給，見 README 7.4）。
     * 這支一律用目前登入 session 的端口過濾，不支援像其他端點那樣傳
     * `portal_id` 跨端口查詢。
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].code string 權限碼
     * @api-response-field data.items[].module_code string 所屬模塊
     * @api-response-field data.items[].domain dict:permission_domain 所屬領域
     * @api-response-field data.items[].portal_keys[] string 標註的適用端口，含 * 代表不限端口
     * @api-response-field data.items[].summary string 說明
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     * @api-response-field data.portal.id int 目前端口ID
     * @api-response-field data.portal.portal_key string 目前端口代碼
     * @api-response-field data.portal.name string 目前端口名稱
     * @api-dict permission_domain api=內部 API,web=Web 頁面
     */
    public function permissionCatalog(Request $request, RoleService $roles)
    {
        // 見上方 description：這支一律用目前 session 端口過濾、判斷是否有權限查看，
        // 不像 index/store 支援跨端口查詢。
        $portalId = (int) $this->currentPortalId($request);
        $this->authorizeOnPortal($request, $portalId, 'api.role.permissionCatalog');

        $portal = Portal::query()->find($portalId);

        $result = $this->paginateOrGet(
            $roles->permissionCatalogQuery($portal?->portal_key)->select(['code', 'module_code', 'domain', 'portal_keys', 'summary']),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['portal'] = $portal ? [
            'id' => $portal->id,
            'portal_key' => $portal->portal_key,
            'name' => $portal->name,
        ] : null;

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/roles
     * @api-tag Role
     * @api-summary 建立角色
     * @api-description
     * 不帶 `portal_id` 時建立在目前端口；跨端口建立的規則跟 `GET /api/roles`
     * 的說明完全相同。
     * `permission_codes` 這裡送的權限碼，一律不能超過建立者自己在目標端口
     * 實際持有的權限範圍——例如某端口管理員自己只持有 `api.order.*`，
     * 就不能建立一個包含 `api.payment.*` 的角色去授權給別人，即使他是這個
     * 端口的管理員也一樣，這是為了防止「授權出去的權限比自己有的還多」這種
     * 越權情況。只有 `is_system`／`is_delegation_root` 不受這個上限限制，
     * 可以自由建立包含任何權限碼的角色。
     * @api-portal *
     * @api-request-field name string 角色名稱
     * @api-request-field permission_codes[] string 權限碼清單（見 /api/roles/permission-catalog）
     * @api-request-field portal_id int 要建立在哪個端口，省略則預設目前端口
     * @api-request-example
     * {"name": "客服人員", "permission_codes": ["api.user.index", "api.role.index"]}
     * @api-response-field data.id int 角色ID
     * @api-response-field data.name string 角色名稱
     * @api-response-field data.permission_codes[] string 權限碼清單
     */
    public function store(Request $request, RoleService $roles)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'permission_codes' => 'array',
            'permission_codes.*' => 'string',
            'portal_id' => 'nullable|integer',
        ]);

        $portalId = $this->resolveTargetPortalId($request, $data['portal_id'] ?? null);
        $this->authorizeOnPortal($request, $portalId, 'api.role.store');

        try {
            $role = $roles->create(
                $this->currentUser($request),
                $portalId,
                $data['name'],
                $data['permission_codes'] ?? [],
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success($this->present($role), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/roles/{role}
     * @api-tag Role
     * @api-summary 更新角色
     * @api-description
     * `permission_codes` 一律整批覆蓋（不是差異更新），送什麼就變成什麼；
     * 授權上限規則與 `POST /api/roles` 完全相同——更新後的權限碼一樣不能
     * 超過操作者自己實際持有的範圍，除非操作者是 `is_system`／
     * `is_delegation_root`。
     * @api-portal *
     * @api-request-field name string 角色名稱
     * @api-request-field permission_codes[] string 權限碼清單（整批覆蓋，見上方 description）
     * @api-request-example
     * {"name": "客服人員", "permission_codes": ["api.user.index"]}
     * @api-response-field data.id int 角色ID
     * @api-response-field data.name string 角色名稱
     * @api-response-field data.permission_codes[] string 權限碼清單
     */
    public function update(Request $request, int $role, RoleService $roles)
    {
        $roleModel = $this->findAuthorizedRole($request, $role, 'api.role.update');

        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'permission_codes' => 'array',
            'permission_codes.*' => 'string',
        ]);

        try {
            $roleModel = $roles->update($this->currentUser($request), $roleModel, $data['name'], $data['permission_codes'] ?? [], $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success($this->present($roleModel));
    }

    /**
     * @api-method DELETE
     * @api-path /api/roles/{role}
     * @api-tag Role
     * @api-summary 刪除角色
     * @api-description
     * 仍有使用者被指派此角色時會拒絕刪除（見 `RoleService::delete`），需先
     * 改派或移除相關使用者的端口權限。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroy(Request $request, int $role, RoleService $roles)
    {
        $roleModel = $this->findAuthorizedRole($request, $role, 'api.role.destroy');

        try {
            $roles->delete($this->currentUser($request), $roleModel, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success();
    }

    // 刻意不用 Laravel 隱式路由模型綁定（{role} 直接型別提示 Role $role）：
    // 隱式綁定會在 SubstituteBindings middleware 階段就查資料庫，這個階段
    // 比本模組掛的 site.context middleware 還早執行，届時 site_current 連線
    // 還是 config/database.php 裡刻意設成無效主機的預留值，查詢一定失敗
    // （見 6.4）。改成收 int，進了 Controller 方法本體（site.context 早就
    // 跑完）才手動查，同一個原因 UserController 也是收 int 手動查，不是
    // 疏漏。授權判斷直接複用 resolveTargetPortalId（用角色實際所屬的
    // portal_id 當「要操作的端口」），跟 index/store 用同一套規則，不用
    // 再自己刻一次「是不是同一個端口」的判斷。
    private function findAuthorizedRole(Request $request, int $roleId, string $code): Role
    {
        $role = Role::query()->findOrFail($roleId);
        $portalId = $this->resolveTargetPortalId($request, $role->portal_id);
        $this->authorizeOnPortal($request, $portalId, $code);

        return $role;
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permission_codes' => $role->permission_codes ?? [],
        ];
    }
}
