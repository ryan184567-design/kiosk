<?php

/* *****************************************
File: AuthController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站管理員登入／登出／個人資料 API，見 README 6.12。
***************************************** */

namespace App\Hq\Auth\Controllers;

use App\Hq\Auth\Services\AuthService;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseHqController;
use Illuminate\Http\Request;

class AuthController extends BaseHqController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * @api-method POST
     * @api-path /api/hq/auth/login
     * @api-tag Auth
     * @api-summary 主站管理員登入
     * @api-description
     * 主站（HQ）是獨立於所有子站之外的管理系統，帳號存在主站自己的
     * `hq_users` 表，不是子站的 `users`。登入不需要指定端口（子站登入才需要
     * `portal_key`）——主站只有一種身份（`is_super_admin` 布林值決定能不能
     * 呼叫站點管理類 API，見 SiteController/SiteModuleController/
     * ImpersonationController），沒有子站那套多端口/多角色權限碼系統。
     * 登入成功會核發一組 JWT，用獨立的 `jwt.hq_secret` 簽章（跟子站 JWT
     * 用不同密鑰，兩邊 token 不能互通、拿子站 token 呼叫主站 API 一定會被
     * 拒絕，反之亦然）。主站沒有子站的「單帳號單 Token」限制，同一個帳號
     * 可以同時有多組有效 session（例如在不同瀏覽器/裝置都保持登入）。
     * 帳號密碼錯誤、帳號被停用（`is_enabled=false`）都回傳同一個籠統錯誤，
     * 不區分是帳號不存在還是密碼錯，避免帳號列舉。
     * @api-request-field username string 登入帳號
     * @api-request-field password string 登入密碼
     * @api-request-example
     * {"username": "hq_admin", "password": "your-password"}
     * @api-response-field data.access_token string 主站 JWT，之後呼叫其他
     * 主站 API 要帶在 `Authorization: Bearer {token}`
     * @api-response-field data.expires_at int Token 過期時間（unix timestamp）
     */
    public function login(Request $request)
    {
        $data = $this->validateInput($request, [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $result = $this->auth->login($data['username'], $data['password']);

        if (! $result['ok']) {
            return $this->fail(ErrorCode::CREDENTIALS_MISSING, __('hq.auth.invalid_credentials'));
        }

        return $this->success([
            'access_token' => $result['token'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    /**
     * @api-method POST
     * @api-path /api/hq/auth/logout
     * @api-tag Auth
     * @api-summary 登出
     * @api-description
     * 把目前這組主站 JWT 對應的 session 標記失效（`is_active=false`），
     * 之後同一組 token 再呼叫任何需要登入的主站 API 都會被
     * `hq.jwt.auth` 中介層拒絕（回 401 缺少憑證）。因為主站沒有單帳號
     * 單 Token 限制（見 login 說明），這個帳號若同時在其他裝置/瀏覽器
     * 還有其他有效 session，完全不受影響，只有「這一次呼叫帶的這組
     * token」失效。
     * @api-no-request-body
     * @api-response-empty
     */
    public function logout(Request $request)
    {
        $session = $request->attributes->get('hq_jwt_session');
        $session?->update(['is_active' => false]);

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/hq/user/profile
     * @api-tag Auth
     * @api-summary 取得目前登入的主站管理員資料
     * @api-description
     * 依 `Authorization: Bearer` 帶的主站 JWT 查出目前是哪個 `hq_users`
     * 帳號登入，回傳基本資料與 `is_super_admin` 旗標——前端可以用這個
     * 旗標決定要不要顯示「站點管理」（開站/停用/模塊開通/跳轉登入）這些
     * 功能入口，因為 SiteController/SiteModuleController/
     * ImpersonationController 底下的 API 全部都要求 `is_super_admin=true`
     * 才能呼叫，不是 true 的帳號呼叫了也只會收到 403。
     * @api-response-field data.id int 主站使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 顯示名稱
     * @api-response-field data.is_super_admin bool 是否為超級管理員（能否呼叫站點管理類 API）
     */
    public function profile(Request $request)
    {
        $user = $this->currentHqUser($request);

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'is_super_admin' => $user->is_super_admin,
        ]);
    }
}
