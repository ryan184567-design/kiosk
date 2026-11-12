<?php

/* *****************************************
File: PermissionService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 7.4：短路優先序 is_system（全域）-> 該端口 is_portal_admin（端口內） -> 一般角色精確匹配 + 萬用字元兩段式判斷。
***************************************** */

namespace App\Support\Permission;

use App\Api\User\Models\User;
use App\Api\User\Models\UserPortalAccess;

// 見 README 7.4：短路優先序 is_system（全域）-> 該端口 is_portal_admin（端口內）
// -> 一般角色精確匹配 + 萬用字元兩段式判斷。
class PermissionService
{
    public function can(User $user, int $portalId, string $code): bool
    {
        return $this->canAny($user, $portalId, [$code]);
    }

    // 見 README 7.1「授權上限」：要判斷「這個人能不能把這些權限碼授權給
    // 別人」，必須每一碼都持有才行（不是有任一碼就好，canAny 是拿來判斷
    // 「能不能用」，這裡判斷的是「能不能把這些碼轉授出去」，語意不同）。
    // is_system／is_portal_admin 短路仍然成立：這兩種身份在該端口內本來
    // 就等於持有全部權限碼。呼叫端仍需自行另外處理 is_delegation_root
    // 的「授權他人不受上限」例外（見 7.1 第 401-402 行），這裡不管——這個
    // 方法只回答「實際持有」，不管「有沒有豁免上限的特權」。
    public function canAll(User $user, int $portalId, array $codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->can($user, $portalId, $code)) {
                return false;
            }
        }

        return true;
    }

    // 見 README 7.1：能不能把「端口管理員」身份授權給別人，取決於自己
    // 是否已經是這個端口的端口管理員（或超級管理員）。is_delegation_root
    // 的例外一樣由呼叫端另外處理，理由同上。
    public function isPortalAdmin(User $user, int $portalId): bool
    {
        if ($user->identityType?->is_system) {
            return true;
        }

        return (bool) UserPortalAccess::query()
            ->where('user_id', $user->id)
            ->where('portal_id', $portalId)
            ->value('is_portal_admin');
    }

    // 見 README 7.1 第 401 行：is_system／is_delegation_root 在「授權他人」
    // 時完全不受上限限制，集中在這裡判斷，RoleService／UserService 等會
    // 授權給別人的地方統一呼叫，不要各自重複寫 is_system/is_delegation_root
    // 的判斷式。
    public function bypassesDelegationCeiling(User $user): bool
    {
        return (bool) ($user->identityType?->is_system || $user->identityType?->is_delegation_root);
    }

    public function canAny(User $user, int $portalId, array $codes): bool
    {
        if ($user->identityType?->is_system) {
            return true;
        }

        /** @var UserPortalAccess|null $access */
        $access = $user->portalAccess()->where('portal_id', $portalId)->first();

        if (! $access) {
            return false;
        }

        if ($access->is_portal_admin) {
            return true;
        }

        $granted = $access->role?->permission_codes ?? [];

        foreach ($codes as $code) {
            foreach ($granted as $pattern) {
                if ($this->matches($pattern, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    // 精確匹配，或萬用字元（api.order.* 匹配 api.order.view）。
    private function matches(string $pattern, string $code): bool
    {
        if ($pattern === $code) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);

            return str_starts_with($code, $prefix);
        }

        return false;
    }
}
