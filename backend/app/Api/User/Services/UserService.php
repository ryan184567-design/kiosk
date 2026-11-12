<?php

/* *****************************************
File: UserService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 使用者／端口存取管理，見 README 7.1、7.2。
***************************************** */

namespace App\Api\User\Services;

use App\Support\Audit\Models\AuditLog;
use App\Support\Identity\Models\IdentityType;
use App\Api\Role\Models\Role;
use App\Api\User\Models\User;
use App\Api\User\Models\UserPortalAccess;
use App\Support\Permission\PermissionService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

// 使用者／端口存取管理，見 README 7.1、7.2。Controller 只做驗證/轉發。
class UserService
{
    public function __construct(private readonly PermissionService $permissions) {}

    // 只列出對「目前端口」有存取權的使用者，這是端口管理員的管理範圍
    // （見 7.1 授權上限：只能管自己端口內的事），不是全站使用者清單。
    // 回傳未執行的 Builder，交由 Controller 用共用的 PaginationHelper
    // 決定要不要分頁（見開發約定第 9 點）。
    public function queryForPortal(int $portalId): Builder
    {
        return UserPortalAccess::query()
            ->with(['user', 'role'])
            ->where('portal_id', $portalId);
    }

    public function presentAccess(UserPortalAccess $access): array
    {
        return [
            'user_id' => $access->user_id,
            'username' => $access->user->username,
            'name' => $access->user->name,
            'is_portal_admin' => $access->is_portal_admin,
            'role_id' => $access->role_id,
            'role_name' => $access->role?->name,
        ];
    }

    // 找一個「已存在但還沒開通這個端口」的帳號，供「把既有使用者加進本端口」
    // 這個情境使用，不是全站使用者搜尋頁面。
    public function search(string $username): ?User
    {
        return User::query()->where('username', $username)->first();
    }

    // 見 7.1 第 4 點：身份別下拉選單一律過濾 is_system，除非目前登入者自己
    // 就是超級管理員——沿用 IdentityType::scopeVisibleTo，不重新刻一套規則。
    public function identityTypeOptions(User $viewer): Collection
    {
        return IdentityType::query()->visibleTo($viewer)->where('is_enabled', true)->orderBy('sort')->get();
    }

    public function createUser(User $creator, string $username, string $password, string $name, int $identityTypeId, ?string $ip = null): User
    {
        if (User::query()->where('username', $username)->exists()) {
            throw new RuntimeException(__('api.user.username_exists'));
        }

        $identityType = IdentityType::query()->visibleTo($creator)->find($identityTypeId);

        if (! $identityType) {
            throw new RuntimeException(__('api.user.identity_type_invalid'));
        }

        $user = User::query()->create([
            'username' => $username,
            'name' => $name,
            'identity_type_id' => $identityType->id,
            'password' => bcrypt($password),
        ]);

        $this->audit($creator, 'user.create', $user, ['username' => $username], $ip);

        return $user;
    }

    // 見 7.2 登入規則第 1 點：超級管理員不查 user_portal_access，這裡對稱地
    // 拒絕對超級管理員做端口授權操作，避免留下一筆永遠用不到、容易誤導的紀錄。
    public function grantOrUpdatePortalAccess(User $granter, User $target, int $portalId, ?int $roleId, bool $isPortalAdmin, ?string $ip = null): UserPortalAccess
    {
        if ($target->identityType?->is_system) {
            throw new RuntimeException(__('api.user.system_no_portal_access'));
        }

        $role = null;

        if ($roleId) {
            $role = Role::query()->find($roleId);

            if (! $role || $role->portal_id !== $portalId) {
                throw new RuntimeException(__('api.user.role_invalid'));
            }
        }

        $this->assertGrantWithinCeiling($granter, $portalId, $isPortalAdmin, $role);

        $access = UserPortalAccess::query()->updateOrCreate(
            ['user_id' => $target->id, 'portal_id' => $portalId],
            ['role_id' => $roleId, 'is_portal_admin' => $isPortalAdmin]
        );

        $this->audit($granter, 'user.grant_access', $target, [
            'portal_id' => $portalId,
            'role_id' => $roleId,
            'is_portal_admin' => $isPortalAdmin,
        ], $ip);

        return $access;
    }

    // 見 README 7.1「授權上限」：授予「端口管理員」身份等於授予該端口全部
    // 權限，只有本身已經是該端口管理員（或 is_system／is_delegation_root）
    // 才能這樣做；指派角色則要求授權者持有該角色的全部權限碼。
    private function assertGrantWithinCeiling(User $granter, int $portalId, bool $isPortalAdmin, ?Role $role): void
    {
        if ($this->permissions->bypassesDelegationCeiling($granter)) {
            return;
        }

        if ($isPortalAdmin && ! $this->permissions->isPortalAdmin($granter, $portalId)) {
            throw new RuntimeException(__('api.user.exceeds_delegation_ceiling'));
        }

        if ($role && ! $this->permissions->canAll($granter, $portalId, $role->permission_codes ?? [])) {
            throw new RuntimeException(__('api.user.exceeds_delegation_ceiling'));
        }
    }

    public function revokePortalAccess(User $actor, User $user, int $portalId, ?string $ip = null): void
    {
        UserPortalAccess::query()->where('user_id', $user->id)->where('portal_id', $portalId)->delete();

        $this->audit($actor, 'user.revoke_access', $user, ['portal_id' => $portalId], $ip);
    }

    public function resetPassword(User $actor, User $user, string $newPassword, ?string $ip = null): void
    {
        $user->update(['password' => bcrypt($newPassword)]);

        // 見 README 15 節第 2 點：審計日誌本身不可以記密碼，這裡的 context
        // 只留「這件事發生了」，不留新密碼的任何片段。
        $this->audit($actor, 'user.reset_password', $user, [], $ip);
    }

    // 見 15 節第 3、5 點：使用者帳號/端口存取屬於「權限變更」與「資料刪改」，
    // 一律寫入該子站自己的 audit_logs。
    private function audit(User $actor, string $action, User $target, array $context, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => "user:{$target->id}",
            'context' => $context,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
