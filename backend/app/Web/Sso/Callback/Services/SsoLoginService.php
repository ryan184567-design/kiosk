<?php

/* *****************************************
File: SsoLoginService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 8.6：SSO 登入的實際業務邏輯，Controller 只負責收請求、設 cookie、 導頁（4 節第 3 點「Controller 只接收經驗證後資料，業務邏輯放在 Service」）。
***************************************** */

namespace App\Web\Sso\Callback\Services;

use App\Api\Auth\Services\AuthService;
use App\Support\Audit\Models\AuditLog;
use App\Support\Identity\Models\IdentityType;
use App\Support\Portal\Models\Portal;
use App\Api\User\Models\User;
use App\Support\Sso\SsoIdentity;
use App\Support\Sso\SsoManager;
use App\Support\Sso\SsoSiteConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

// 見 README 8.6：SSO 登入的實際業務邏輯，Controller 只負責收請求、設 cookie、
// 導頁（4 節第 3 點「Controller 只接收經驗證後資料，業務邏輯放在 Service」）。
class SsoLoginService
{
    public function __construct(
        private readonly SsoSiteConfig $siteConfig,
        private readonly SsoManager $sso,
        private readonly AuthService $auth,
    ) {}

    /**
     * @return array{ok: true, token: string, expiresAt: int}|array{ok: false, error: string}
     */
    public function handle(Request $request): array
    {
        $config = $this->siteConfig->load();

        if (empty($config['sso_enabled'])) {
            return ['ok' => false, 'error' => 'sso_disabled'];
        }

        if (empty($config['sso_driver'])) {
            return ['ok' => false, 'error' => 'sso_not_configured'];
        }

        $identity = $this->sso->driver($config['sso_driver'])->resolveIdentity($request, $config);

        if (! $identity || ! $identity->username) {
            return ['ok' => false, 'error' => 'identity_resolve_failed'];
        }

        $user = User::query()->where('username', $identity->username)->first();

        if (! $user) {
            if (empty($config['sso_auto_create_user'])) {
                return ['ok' => false, 'error' => 'user_not_found'];
            }

            $user = $this->createUser($identity, $config);
        }

        $portal = ! empty($config['sso_portal_key'])
            ? Portal::query()->where('portal_key', $config['sso_portal_key'])->where('is_enabled', true)->first()
            : null;

        if (! $portal) {
            return ['ok' => false, 'error' => 'portal_not_found'];
        }

        // 見 7.2 登入規則：SSO 只負責「證明這是誰」，不繞過端口存取規則——
        // 沒有 user_portal_access 紀錄一樣拒絕，不因為走 SSO 就自動放行，
        // 避免變成繞過既有權限體系的後門。
        $access = $this->auth->resolvePortalAccess($user, $portal);

        if (! $access['ok']) {
            return $access;
        }

        $issued = $this->auth->issueSession($user->id, $portal->id, $access['roleId'], 'sso');

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'sso.login',
            'target' => "portal:{$portal->portal_key}",
            'context' => ['driver' => $config['sso_driver'], 'external_id' => $identity->externalId],
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return ['ok' => true, 'token' => $issued['token'], 'expiresAt' => $issued['expires_at']];
    }

    // 見 8.6：自動建立帳號只是「這個人是誰」，不代表任何端口權限——新建的
    // 帳號沒有 user_portal_access 紀錄，上面的 resolvePortalAccess 一樣會擋下來，
    // 要由管理員另外指派端口/角色，不因為走過 SSO 就自動取得業務權限。
    private function createUser(SsoIdentity $identity, array $config): User
    {
        $typeCode = $config['sso_default_identity_type'] ?? 'staff';
        $identityType = IdentityType::query()->where('code', $typeCode)->first();

        if (! $identityType) {
            throw new RuntimeException(__('api.sso.default_identity_type_missing', ['code' => $typeCode]));
        }

        return User::query()->create([
            'username' => $identity->username,
            'name' => $identity->name ?: $identity->username,
            'identity_type_id' => $identityType->id,
            // SSO 帳號不應該還能用密碼登入，給一組不可能猜中、使用者自己也
            // 不知道的隨機密碼，不是留空（留空在部分驗證邏輯下可能被誤判）。
            'password' => bcrypt(Str::random(40)),
        ]);
    }
}
