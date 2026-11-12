<?php

/* *****************************************
File: UserController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 使用者／端口存取管理，見 README 7.1、7.2。
***************************************** */

namespace App\Api\User\Controllers;

use App\Api\User\Services\UserService;
use App\Api\User\Models\User;
use App\Api\User\Models\UserPortalAccess;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Permission\PermissionService;
use App\Support\Portal\Models\Portal;
use Illuminate\Http\Request;
use RuntimeException;

// 使用者／端口存取管理，見 README 7.1、7.2。預設只作用在目前登入 session
// 所屬的端口；帶 portal_id 且呼叫者有權管理該端口，才能跨到別的端口
// （見 BaseApiController::resolveTargetPortalId、RoleController 開頭註解——
// 同一套「有些端口沒有自己的端口管理員，得靠後台統一管理」的設計）。
//
// UserService 用方法參數注入、不用建構子注入，理由見 RoleController 開頭
// 註解與 README 9.2：Laravel Router 的 gatherMiddleware() 階段會提前完整
// 建構 Controller，建構子依賴會在 site.context 綁定覆寫類別前就被鎖死。
class UserController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/users
     * @api-tag User
     * @api-summary 列出使用者
     * @api-description
     * 回傳的是「端口存取紀錄」清單，不是全站使用者清單——只列出對目前
     * 端口（或指定的 `portal_id`）有存取權的使用者，這是端口管理員實際
     * 管得到的範圍。不帶 `portal_id` 時列出目前登入端口；帶了且跟目前
     * 端口不同時，跨端口權限規則跟 `GET /api/roles` 完全相同（只有
     * `is_system`／`is_delegation_root`，或呼叫者本身就是目標端口的端口
     * 管理員，才能查詢別的端口）。
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].user_id int 使用者ID
     * @api-response-field data.items[].username string 帳號
     * @api-response-field data.items[].name string 姓名
     * @api-response-field data.items[].is_portal_admin bool 是否為端口管理員
     * @api-response-field data.items[].role_id int 角色ID（is_portal_admin=true 時可能為 null）
     * @api-response-field data.items[].role_name string 角色名稱
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function index(Request $request, UserService $users)
    {
        $portalId = $this->resolveTargetPortalId($request, $request->integer('portal_id') ?: null);
        $this->authorizeOnPortal($request, $portalId, 'api.user.index');

        $result = $this->paginateOrGet(
            $users->queryForPortal($portalId),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['items'] = collect($result['items'])->map(fn (UserPortalAccess $access) => $users->presentAccess($access))->all();

        return $this->success($result);
    }

    /**
     * @api-method GET
     * @api-path /api/users/search
     * @api-tag User
     * @api-summary 依帳號搜尋既有使用者（供加入本端口使用）
     * @api-description
     * 查詢字串參數 `username`。找不到不算錯誤，`data.found=false`、`data.user=null`。
     * @api-portal *
     * @api-response-field data.found bool 是否找到
     * @api-response-field data.user object 找到時的使用者資料（id/username/name）
     */
    public function search(Request $request, UserService $users)
    {
        $data = $this->validateInput($request, ['username' => 'required|string']);
        $user = $users->search($data['username']);

        return $this->success([
            'found' => (bool) $user,
            'user' => $user ? ['id' => $user->id, 'username' => $user->username, 'name' => $user->name] : null,
        ]);
    }

    /**
     * @api-method GET
     * @api-path /api/users/identity-type-options
     * @api-tag User
     * @api-summary 取得可指派的身份別選單
     * @api-description
     * 供建立新帳號時的身份別下拉選單使用。**一般呼叫者看不到 `is_system`
     * 的身份別選項**（超級管理員這個身份別本身就不開放透過一般管理介面
     * 指派，避免一般管理員誤建出另一個超級管理員帳號）；只有呼叫者自己
     * 就是超級管理員（`is_system=true`）時，這份清單才會包含 `is_system`
     * 的選項，讓超級管理員之間可以互相建立彼此的帳號。
     * @api-portal *
     * @api-response-field data.items[].id int 身份別ID
     * @api-response-field data.items[].code string 身份別代碼
     * @api-response-field data.items[].name string 身份別名稱
     */
    public function identityTypeOptions(Request $request, UserService $users)
    {
        $items = $users->identityTypeOptions($this->currentUser($request));

        return $this->success(['items' => $items->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name])->all()]);
    }

    /**
     * @api-method GET
     * @api-path /api/users/grantable-portals
     * @api-tag User
     * @api-summary 列出目前使用者能授予「端口管理員」身份的端口
     * @api-description
     * 只回傳呼叫者實際能操作的端口子集，精準對應
     * `UserService::assertGrantWithinCeiling` 授予端口管理員身份時的判斷
     * 式本身：`is_system`／`is_delegation_root` 回全部已啟用端口；一般
     * 使用者只回自己本身就是端口管理員的端口——不像
     * `GET /api/menus/portals` 那樣連「持有該端口某個特定權限碼」也算，
     * 那份清單是給切換選單管理頁面用的，混進來這裡會讓使用者以為勾了就
     * 能授權，送出後才在後端被 `exceeds_delegation_ceiling` 擋下，體驗上
     * 前後矛盾。供「新增使用者／編輯權限」對話框勾選端口管理員身份時的
     * 端口清單使用。
     * @api-portal *
     * @api-response-field data[].id int 端口ID
     * @api-response-field data[].portal_key string 端口代碼
     * @api-response-field data[].name string 端口名稱
     */
    public function grantablePortals(Request $request)
    {
        $user = $this->currentUser($request);
        $permissions = app(PermissionService::class);
        $bypass = $permissions->bypassesDelegationCeiling($user);

        $portals = Portal::query()->where('is_enabled', true)->orderBy('id')->get()->filter(
            fn (Portal $portal) => $bypass || $permissions->isPortalAdmin($user, $portal->id)
        )->values();

        return $this->success(['items' => $portals->map(fn ($portal) => [
            'id' => $portal->id,
            'portal_key' => $portal->portal_key,
            'name' => $portal->name,
        ])->all()]);
    }

    /**
     * @api-method POST
     * @api-path /api/users
     * @api-tag User
     * @api-summary 建立新帳號（不含端口存取）
     * @api-description
     * 這支**只建立帳號本身**，新帳號建立完全不會自動取得任何端口的存取
     * 權——「這個人是誰」（身份，帳號本身）與「這個人能做什麼」（權限，
     * 端口存取/角色）是兩件分開的事，帳號建立好之後要讓它能實際登入某個
     * 端口使用，必須另外呼叫 `POST /api/users/{user}/portal-access` 明確
     * 授權，不會因為帳號存在就自動能用任何端口。
     * @api-portal *
     * @api-request-field username string 登入帳號
     * @api-request-field password string 密碼
     * @api-request-field name string 姓名
     * @api-request-field identity_type_id int 身份別ID（見 /api/users/identity-type-options）
     * @api-request-example
     * {"username": "staff01", "password": "TempPass123", "name": "王小明", "identity_type_id": 3}
     * @api-response-field data.id int 使用者ID
     * @api-response-field data.username string 帳號
     */
    public function store(Request $request, UserService $users)
    {
        $data = $this->validateInput($request, [
            'username' => 'required|string|max:100',
            'password' => 'required|string|min:8',
            'name' => 'required|string|max:100',
            'identity_type_id' => 'required|integer',
        ]);

        try {
            $user = $users->createUser(
                $this->currentUser($request),
                $data['username'],
                $data['password'],
                $data['name'],
                $data['identity_type_id'],
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success(['id' => $user->id, 'username' => $user->username], 201);
    }

    /**
     * @api-method POST
     * @api-path /api/users/{user}/portal-access
     * @api-tag User
     * @api-summary 授予／更新使用者在某個端口的存取權
     * @api-description
     * 不帶 `portal_id` 時作用在目前登入端口；跨端口授權規則跟
     * `GET /api/roles` 相同。目標使用者若是超級管理員身份
     * （`identity_types.is_system=true`），這支會直接拒絕——超級管理員
     * 本來就不透過端口存取表授權，不需要（也不能）額外指派端口。
     * 這裡授予的權限一律不能超過操作者自己的範圍：要設定
     * `is_portal_admin=true` 時，操作者自己必須也是這個端口的端口管理員
     * 才行；要指派 `role_id` 時，操作者自己必須持有該角色包含的**全部**
     * 權限碼（不是任一個，是全部都要有）。`is_system`／`is_delegation_root`
     * 完全不受這兩項限制。這支同時也是「更新」——對已經有這個端口存取權
     * 的使用者再呼叫一次，會直接覆蓋成新的 `role_id`／`is_portal_admin`。
     * @api-portal *
     * @api-request-field role_id int 角色ID，選 is_portal_admin 模式時可不填
     * @api-request-field is_portal_admin bool 是否設為端口管理員（等同擁有該端口全部權限，不需另外指派角色）
     * @api-request-field portal_id int 要授權哪個端口，省略則預設目前端口
     * @api-request-example
     * {"role_id": 2, "is_portal_admin": false}
     * @api-response-field data.role_id int 角色ID
     * @api-response-field data.is_portal_admin bool 是否為端口管理員
     */
    public function grantAccess(Request $request, int $user, UserService $users)
    {
        $data = $this->validateInput($request, [
            'role_id' => 'nullable|integer',
            'is_portal_admin' => 'boolean',
            'portal_id' => 'nullable|integer',
        ]);

        $portalId = $this->resolveTargetPortalId($request, $data['portal_id'] ?? null);
        $this->authorizeOnPortal($request, $portalId, 'api.user.grantAccess');
        $targetUser = User::query()->findOrFail($user);

        try {
            $access = $users->grantOrUpdatePortalAccess(
                $this->currentUser($request),
                $targetUser,
                $portalId,
                $data['role_id'] ?? null,
                (bool) ($data['is_portal_admin'] ?? false),
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success(['role_id' => $access->role_id, 'is_portal_admin' => $access->is_portal_admin]);
    }

    /**
     * @api-method DELETE
     * @api-path /api/users/{user}/portal-access
     * @api-tag User
     * @api-summary 撤銷使用者在某個端口的存取權
     * @api-description
     * 直接刪除該使用者在這個端口的 `user_portal_access` 紀錄，之後這個
     * 帳號無法再用這個端口登入（其他端口的存取權不受影響）。不帶
     * `portal_id` 時作用在目前登入端口；跨端口撤銷的權限規則跟
     * `GET /api/roles` 相同。對本來就沒有這個端口存取權的使用者呼叫這支
     * 不會報錯，視為操作已達成目的（冪等）。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function revokeAccess(Request $request, int $user, UserService $users)
    {
        $portalId = $this->resolveTargetPortalId($request, $request->integer('portal_id') ?: null);
        $this->authorizeOnPortal($request, $portalId, 'api.user.revokeAccess');
        $targetUser = User::query()->findOrFail($user);
        $users->revokePortalAccess($this->currentUser($request), $targetUser, $portalId, $request->ip());

        return $this->success();
    }

    /**
     * @api-method PUT
     * @api-path /api/users/{user}/password
     * @api-tag User
     * @api-summary 重設使用者密碼
     * @api-portal *
     * @api-request-field password string 新密碼
     * @api-request-example
     * {"password": "NewPass456"}
     * @api-response-empty
     */
    public function resetPassword(Request $request, int $user, UserService $users)
    {
        $data = $this->validateInput($request, ['password' => 'required|string|min:8']);
        $targetUser = User::query()->findOrFail($user);
        $users->resetPassword($this->currentUser($request), $targetUser, $data['password'], $request->ip());

        return $this->success();
    }
}
