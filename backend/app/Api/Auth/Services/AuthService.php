<?php

/* *****************************************
File: AuthService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站登入邏輯，見 README 7.2 登入規則、11.1 單帳號單 Token。
***************************************** */

namespace App\Api\Auth\Services;

use App\Api\Auth\Models\UserJwtSession;
use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Auth\JwtService;
use App\Support\Portal\Models\Portal;
use Illuminate\Support\Facades\Hash;

// 子站登入邏輯，見 README 7.2 登入規則、11.1 單帳號單 Token。
class AuthService
{
    public function __construct(private readonly JwtService $jwt) {}

    /**
     * @return array{ok: true, token: string, expires_at: int}|array{ok: false, error: string}
     */
    public function login(string $username, string $password, string $portalKey, ?string $ip = null): array
    {
        $user = User::query()->where('username', $username)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }

        $portal = Portal::query()->where('portal_key', $portalKey)->where('is_enabled', true)->first();

        if (! $portal) {
            return ['ok' => false, 'error' => 'portal_not_found'];
        }

        $access = $this->resolvePortalAccess($user, $portal);

        if (! $access['ok']) {
            // 密碼已確認正確、只是走錯登入入口（例如公司帳號在後台登入頁登入）：告訴前端這個帳號
            // 可以登入哪些端口，由前端自動切換。只在密碼正確時才回這個資訊，不會洩漏帳號歸屬。
            if ($access['error'] === 'portal_access_denied') {
                $alternatives = $this->loginablePortalKeys($user, $portal->id);

                if ($alternatives !== []) {
                    return ['ok' => false, 'error' => 'portal_mismatch', 'portal_keys' => $alternatives];
                }
            }

            return $access;
        }

        $issued = $this->issueSession($user->id, $portal->id, $access['roleId'], 'login');

        // 見 README 15 節第 3、5 點：登入屬於必須審計的操作，寫入該子站自己
        // 的 audit_logs；只記成功登入，理由同 sso.login／hq_impersonation.login
        // 既有寫法——失敗嘗試不代表任何人身份已確認，記了反而容易誤導稽核。
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'auth.login',
            'target' => "portal:{$portal->portal_key}",
            'context' => [],
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return $issued;
    }

    // 見 7.2 登入規則第 1-4 點：同一套「這個帳號能不能用這個端口、用哪個
    // 角色」判斷，密碼登入（上方 login）與其他核發管道（見 6.11 跳轉登入、
    // 8.6 SSO 登入）共用，不要各自重刻一份、日後規則跑掉。
    /**
     * @return array{ok: true, roleId: ?int}|array{ok: false, error: string}
     */
    public function resolvePortalAccess(User $user, Portal $portal): array
    {
        // 超級管理員短路，不查 user_portal_access。
        if ($user->identityType?->is_system) {
            return ['ok' => true, 'roleId' => null];
        }

        $access = $user->portalAccess()->where('portal_id', $portal->id)->first();

        if (! $access) {
            return ['ok' => false, 'error' => 'portal_access_denied'];
        }

        if ($portal->admin_only && ! $access->is_portal_admin) {
            return ['ok' => false, 'error' => 'portal_admin_only'];
        }

        return ['ok' => true, 'roleId' => $access->role_id];
    }

    // 這個帳號實際能登入的其他端口代碼（已啟用、有存取權、符合 admin_only 限制），
    // 判斷規則與 resolvePortalAccess 一致，排除目前要登入的端口。
    private function loginablePortalKeys(User $user, int $excludePortalId): array
    {
        return Portal::query()
            ->where('is_enabled', true)
            ->where('id', '!=', $excludePortalId)
            ->orderBy('id')
            ->get()
            ->filter(fn (Portal $portal) => $this->resolvePortalAccess($user, $portal)['ok'])
            ->pluck('portal_key')
            ->values()
            ->all();
    }

    // 見 README 7 節：編輯自己的個人資料，不能改 username（登入帳號本身
    // 是識別碼，允許自己改掉容易造成登入混亂／跟審計日誌對不上人，要改
    // username 屬於帳號管理範疇，不是「個人資料」）。
    public function updateProfile(User $user, string $name, ?string $email, ?string $ip = null): User
    {
        $user->update(['name' => $name, 'email' => $email]);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'auth.update_profile',
            'target' => "user:{$user->id}",
            'context' => [],
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, ?string $ip = null): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return ['ok' => false, 'error' => 'current_password_incorrect'];
        }

        $user->update(['password' => bcrypt($newPassword)]);

        // 見 README 15 節第 2 點：審計日誌本身不可以記密碼，這裡的 context
        // 只留「這件事發生了」，不留新／舊密碼的任何片段。
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'auth.change_password',
            'target' => "user:{$user->id}",
            'context' => [],
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: true, token: string, expires_at: int}
     */
    public function issueSession(int $userId, int $portalId, ?int $roleId, string $issuedVia): array
    {
        if (config('jwt_session.single_user_session')) {
            // 見 11.1：限制範疇是「同一帳號在同一端口」。
            UserJwtSession::query()
                ->where('user_id', $userId)
                ->where('portal_id', $portalId)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $issued = $this->jwt->issue([
            'sub' => $userId,
            'portal_id' => $portalId,
            'role_id' => $roleId,
        ], (string) config('jwt.site_secret'));

        UserJwtSession::query()->create([
            'user_id' => $userId,
            'portal_id' => $portalId,
            'role_id' => $roleId,
            'jti' => $issued['jti'],
            'issued_via' => $issuedVia,
            'last_access_at' => now(),
            'is_active' => true,
        ]);

        return ['ok' => true, 'token' => $issued['token'], 'expires_at' => $issued['expires_at']];
    }
}
