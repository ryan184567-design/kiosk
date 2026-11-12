<?php

/* *****************************************
File: RoleService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色管理業務邏輯，見 README 7.2、7.4。
***************************************** */

namespace App\Api\Role\Services;

use App\Api\Role\Models\Role;
use App\Api\User\Models\User;
use App\Api\User\Models\UserPortalAccess;
use App\Support\Audit\Models\AuditLog;
use App\Support\Permission\Models\Permission;
use App\Support\Permission\PermissionService;
use App\Support\Portal\Models\Portal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use RuntimeException;

// 角色管理業務邏輯，見 README 7.2、7.4。Controller 只做驗證/轉發（4 節第 3 點）。
class RoleService
{
    public function __construct(private readonly PermissionService $permissions) {}

    // 回傳未執行的 Builder，交由 Controller 用共用的 PaginationHelper
    // 決定要不要分頁（見開發約定第 9 點），Service 本身不管分頁參數。
    public function queryForPortal(int $portalId): Builder
    {
        return Role::query()->where('portal_id', $portalId)->orderBy('name');
    }

    // 見 PermissionAuditCeilingCommand 的同一套判斷：web 權限目前沒有可靠的
    // 端口關聯資料（permission:sync-web 一律存 portal_keys=[]，見該指令的
    // 說明），過濾時完全略過、一律顯示，避免誤判成「不適用此端口」而整批
    // 消失；api 權限才用 portal_keys 做「@api-portal * 或包含這個端口」判斷。
    public function permissionCatalogQuery(?string $portalKey = null): Builder
    {
        $query = Permission::query()->orderBy('module_code')->orderBy('code');

        if ($portalKey !== null) {
            $query->where(function (Builder $q) use ($portalKey) {
                $q->where('domain', 'web')
                    ->orWhereJsonContains('portal_keys', '*')
                    ->orWhereJsonContains('portal_keys', $portalKey);
            });
        }

        return $query;
    }

    public function create(User $granter, int $portalId, string $name, array $permissionCodes, ?string $ip = null): Role
    {
        $this->assertNameAvailable($portalId, $name);
        $this->assertCodesExist($portalId, $permissionCodes);
        $this->assertWithinCeiling($granter, $portalId, $permissionCodes);

        $role = Role::query()->create([
            'portal_id' => $portalId,
            'name' => $name,
            'permission_codes' => $permissionCodes,
        ]);

        $this->audit($granter, 'role.create', $role, $ip);

        return $role;
    }

    public function update(User $granter, Role $role, string $name, array $permissionCodes, ?string $ip = null): Role
    {
        if ($name !== $role->name) {
            $this->assertNameAvailable($role->portal_id, $name);
        }

        $this->assertCodesExist($role->portal_id, $permissionCodes);
        $this->assertWithinCeiling($granter, $role->portal_id, $permissionCodes);

        $role->update(['name' => $name, 'permission_codes' => $permissionCodes]);

        $this->audit($granter, 'role.update', $role, $ip);

        return $role;
    }

    // 見 7.4：刪除前檢查是否仍有人指派這個角色，避免留下懸空 role_id
    // （UserPortalAccess.role_id 沒有資料庫層 FK 約束，不能只靠資料庫擋）。
    public function delete(User $actor, Role $role, ?string $ip = null): void
    {
        if (UserPortalAccess::query()->where('role_id', $role->id)->exists()) {
            throw new RuntimeException(__('api.role.still_assigned'));
        }

        $this->audit($actor, 'role.delete', $role, $ip);

        $role->delete();
    }

    // 見 README 15 節第 3、5 點：角色屬於「權限變更」，一律寫入該子站自己的
    // audit_logs，不能只留在應用日誌裡（那邊不保證保留、也不方便稽核查詢）。
    private function audit(User $actor, string $action, Role $role, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => "role:{$role->id}",
            'context' => [
                'portal_id' => $role->portal_id,
                'name' => $role->name,
                'permission_codes' => $role->permission_codes ?? [],
            ],
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    // 見 migration 2024_01_03_000000 說明：唯一鍵從資料庫層移到這裡檢查，
    // 因為 SoftDeletes 全域 scope 本來就會排除已刪除的紀錄，語意才正確
    // （刪除後名稱可以重新使用）。
    private function assertNameAvailable(int $portalId, string $name): void
    {
        if (Role::query()->where('portal_id', $portalId)->where('name', $name)->exists()) {
            throw new RuntimeException(__('api.role.name_exists', ['name' => $name]));
        }
    }

    // 見 12.6 的同一個原則：權限碼寫錯字不可靜默接受，建立/更新角色時就要擋，
    // 不要等到 permission:audit-ceiling 事後才發現。這裡同時把該指令原本
    // 只能事後抓的「跨端口權限外洩」（權限碼標註的 @api-portal 不含這個
    // 角色所屬端口）提前擋在寫入當下，理由同上——不要等離線稽核才發現。
    // web 權限沒有可靠端口關聯資料的例外處理，跟 permissionCatalogQuery 用
    // 同一套判斷（見該方法說明）。
    private function assertCodesExist(int $portalId, array $codes): void
    {
        $portalKey = Portal::query()->find($portalId)?->portal_key;

        foreach ($codes as $code) {
            $matches = str_ends_with($code, '.*')
                ? Permission::query()->where('code', 'like', substr($code, 0, -1).'%')->get()
                : Permission::query()->where('code', $code)->get();

            if ($matches->isEmpty()) {
                throw new RuntimeException(__('api.role.permission_code_not_found', ['code' => $code]));
            }

            $inScope = $matches->every(function (Permission $p) use ($portalKey) {
                if ($p->domain === 'web') {
                    return true;
                }

                $allowed = $p->portal_keys ?? [];

                return in_array('*', $allowed, true) || in_array($portalKey, $allowed, true);
            });

            if (! $inScope) {
                throw new RuntimeException(__('api.role.permission_code_wrong_portal', ['code' => $code]));
            }
        }
    }

    // 見 README 7.1「授權上限」：一般端口管理員／握有部分權限碼的使用者，
    // 只能把自己在該端口實際持有的權限碼授權出去（PermissionService::canAll
    // 對端口管理員／超級管理員本來就會短路成 true，所以他們授權任何碼都會
    // 通過，符合「端口管理員在自己端口內不受限」）；is_system／
    // is_delegation_root 完全不受此限。
    private function assertWithinCeiling(User $granter, int $portalId, array $codes): void
    {
        if ($this->permissions->bypassesDelegationCeiling($granter)) {
            return;
        }

        if (! $this->permissions->canAll($granter, $portalId, $codes)) {
            throw new RuntimeException(__('api.role.exceeds_delegation_ceiling'));
        }
    }
}
