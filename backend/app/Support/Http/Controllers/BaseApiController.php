<?php

/* *****************************************
File: BaseApiController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 所有 app/Api、app/Web 模塊的 Controller 基底類，見 README 開發約定第 1 點： Controller 只接收經驗證後資料，業務邏輯放在 Service（見 4 節第 3 點）。
***************************************** */

namespace App\Support\Http\Controllers;

use App\Support\Portal\Models\Portal;
use App\Api\User\Models\User;
use App\Support\Api\ApiResponder;
use App\Support\Permission\PermissionService;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// 所有 app/Api、app/Web 模塊的 Controller 基底類，見 README 開發約定第 1 點：
// Controller 只接收經驗證後資料，業務邏輯放在 Service（見 4 節第 3 點）。
abstract class BaseApiController extends Controller
{
    use ValidatesRequests;

    // 統一入參驗證，僅允許取得已聲明規則的欄位（見 4 節第 2 點）。
    protected function validateInput(Request $request, array $rules, array $messages = []): array
    {
        return $request->validate($rules, $messages);
    }

    protected function success(array $data = [], int $status = 200)
    {
        return ApiResponder::success($data, $status);
    }

    protected function fail(int $errorCode, ?string $message = null, ?int $status = null)
    {
        return ApiResponder::fail($errorCode, $message, $status);
    }

    // 見 README 7.2：登入 session 的權限判斷皆以 portal_id/role_id 為準，
    // 由 EnsureJwtAuthenticated middleware 放進 request attributes。
    protected function currentUser(Request $request): ?User
    {
        return $request->attributes->get('auth_user');
    }

    protected function currentPortalId(Request $request): ?int
    {
        return $request->attributes->get('auth_portal_id');
    }

    protected function currentRoleId(Request $request): ?int
    {
        return $request->attributes->get('auth_role_id');
    }

    // 見 README 7.1「授權上限」與使用者反饋：有些端口（例如純前台端口）
    // 本來就不會有自己的端口管理員，權限得靠後台統一管理來給；後台統一
    // 管理不是只有 is_system／is_delegation_root 能用——一般端口管理員
    // 也可以在後台管理畫面操作，只是範圍限制在「自己實際擔任端口管理員」
    // 的那些端口（不因為目前登入 session 剛好是哪個端口就被侷限住，一人
    // 可能同時是好幾個端口的管理員，見 7.2）；is_system／is_delegation_root
    // 則可以按任意端口管理，不受此限（見 7.1 第 401 行「授權他人不受上限」）。
    // $requestedPortalId 為 null 或等於目前 session 端口時，三種身份都一樣
    // 只作用在目前端口，行為不變（維持既有的單一登入 session 使用情境）。
    protected function resolveTargetPortalId(Request $request, ?int $requestedPortalId): int
    {
        $sessionPortalId = (int) $this->currentPortalId($request);

        if ($requestedPortalId === null || $requestedPortalId === $sessionPortalId) {
            return $sessionPortalId;
        }

        $user = $this->currentUser($request);
        $permissions = app(PermissionService::class);

        $allowed = $permissions->bypassesDelegationCeiling($user)
            || $permissions->isPortalAdmin($user, $requestedPortalId);

        if (! $allowed) {
            abort(403, __('api.error_catalog.permission_denied'));
        }

        Portal::query()->where('is_enabled', true)->findOrFail($requestedPortalId);

        return $requestedPortalId;
    }

    // 見 resolveTargetPortalId 開頭說明：路由層的 permission.api:{code} middleware
    // 只會拿「目前登入 session 的端口」去比對，對這種「目標端口可能跟登入
    // session 端口不同」的跨端口管理動作完全不適用（session 在 admin 端口、
    // 實際要操作 shop 端口時，middleware 會拿 admin 端口的權限碼去判斷，
    // 邏輯上就是錯的）。這類 Controller 改成不掛 permission.api middleware，
    // resolveTargetPortalId 決定好「目標端口」之後，這裡才真正對著目標端口
    // 判斷該動作的權限碼；is_system／is_delegation_root 這裡整段略過，因為
    // 對它們來說「能不能管理這個端口」本身已經在 resolveTargetPortalId 判斷
    // 過，管理端口內部要做什麼動作是它們的特權範圍，不需要逐碼再檢查一次
    // （否則 is_delegation_root 對自己毫無 user_portal_access 的端口，永遠
    // 會被下面的 can() 擋下來，跟 7.1 401 行「授權他人不受上限」矛盾）。
    protected function authorizeOnPortal(Request $request, int $portalId, string $code): void
    {
        $user = $this->currentUser($request);
        $permissions = app(PermissionService::class);

        if ($permissions->bypassesDelegationCeiling($user)) {
            return;
        }

        if (! $permissions->can($user, $portalId, $code)) {
            abort(403, __('api.error_catalog.permission_denied'));
        }
    }
}
