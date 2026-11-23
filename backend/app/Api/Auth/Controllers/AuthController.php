<?php

/* *****************************************
File: AuthController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站登入／登出／個人資料 API，見 README 7.2、11.1。
***************************************** */

namespace App\Api\Auth\Controllers;

use App\Api\Auth\Services\AuthService;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

class AuthController extends BaseApiController
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * @api-method POST
     * @api-path /api/auth/login
     * @api-tag Auth
     * @api-summary 登入
     * @api-description
     * 子站登入一律用帳號（`username`）＋密碼，不使用 email。因為一個帳號可能
     * 同時被開通多個端口（`portal_key`，例如同時是 admin 後台的一般使用者、
     * 又是 vendor 端口的操作員），登入時必須明確指定要進入哪一個端口，
     * 系統才知道要用哪個端口對應的角色/權限碼核發 JWT——同一個帳號在不同
     * 端口的權限完全獨立，不會互相套用。
     * 帳號密碼正確、但這個帳號沒有被開通 `portal_key` 對應的端口存取權時，
     * 一律拒絕登入，不會 fallback 到其他端口；若該端口是 `admin_only`
     * （只允許端口管理員登入），一般使用者即使密碼正確也會被拒絕
     * （回傳 `portal_admin_only` 錯誤，區別於帳密錯誤）。
     * 登入成功後，若 `.env` 的 `JWT_SINGLE_USER_SESSION=true`（預設），
     * 這個帳號在「這個端口」原本持有的其他有效 JWT 會全部立即失效
     * （單帳號單 Token，範疇是同一帳號 + 同一端口的組合，不影響這個帳號
     * 在其他端口的登入狀態）。
     *
     * `portal_key` 怎麼填：必填，填「端口代碼」字串，需與後台「端口管理」裡已啟用的端口的 `portal_key` 完全一致（區分大小寫）。
     * 目前系統預設只有一個端口：`admin`（管理後台），登入後台就填 `admin`。
     * 之後若在「端口管理」新增其他端口（如 vendor），才會多出可填的值；
     * 填了不存在、已停用或該帳號沒被開通的端口，一律登入失敗，不會自動改用其他端口。
     * @api-portal *
     * @api-dict portal_key admin=管理後台（預設唯一端口，登入後台請填此值）
     * @api-request-field username string 登入帳號，必填。預設帳號見 README 第 7 節（子站超級管理員 admin、系統管理員為站代碼）
     * @api-request-field password string 密碼，必填
     * @api-request-field portal_key dict:portal_key 要進入的端口代碼，必填，目前可選值只有 admin
     * @api-request-example
     * {"username": "admin", "password": "!@#123456", "portal_key": "admin"}
     * @api-response-field data.access_token string JWT
     * @api-response-field data.expires_at int 過期時間（unix timestamp）
     */
    public function login(Request $request)
    {
        $data = $this->validateInput($request, [
            'username' => 'required|string',
            'password' => 'required|string',
            'portal_key' => 'required|string',
        ]);

        $result = $this->auth->login($data['username'], $data['password'], $data['portal_key'], $request->ip());

        if (! $result['ok']) {
            return match ($result['error']) {
                'portal_admin_only' => $this->fail(ErrorCode::PORTAL_ADMIN_ONLY),
                default => $this->fail(ErrorCode::CREDENTIALS_MISSING, __('api.auth.invalid_credentials')),
            };
        }

        return $this->success([
            'access_token' => $result['token'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    /**
     * @api-method POST
     * @api-path /api/auth/logout
     * @api-tag Auth
     * @api-summary 登出
     * @api-description
     * 把目前呼叫這支 API 所帶的這組 JWT 對應的 session 標記失效
     * （`is_active=false`），之後同一組 token 再呼叫任何需要登入的 API
     * 都會被拒絕（回缺少憑證）。只影響「這一次呼叫帶的這組 token」，
     * 不影響同一個帳號在其他端口的其他有效 session（例如同時在 admin
     * 端口與 vendor 端口都保持登入時，登出 admin 不會連帶登出 vendor）。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function logout(Request $request)
    {
        $session = $request->attributes->get('jwt_session');
        $session?->update(['is_active' => false]);

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/user/profile
     * @api-tag Auth
     * @api-summary 取得個人資料
     * @api-description
     * 回傳目前登入 session 自己的資料，不限定端口——任何已登入的端口都能呼叫
     * 這支查自己的資料，不是查特定端口才開放的業務資料。
     * @api-portal *
     * @api-response-field data.id int 使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 顯示名稱
     * @api-response-field data.email string 電子郵件
     * @api-response-field data.portal_id int 本次登入所屬端口
     * @api-response-field data.issued_via dict:jwt_issued_via 本次 session 的建立來源，供前端判斷是否要顯示「目前透過主站跳轉以超級管理員身份操作」的提示列（見 6.11 第 4 點）
     * @api-dict jwt_issued_via login=一般帳密登入,sso=SSO 登入（見 8.6）,hq_impersonation=主站跳轉登入（見 6.11）
     */
    public function profile(Request $request)
    {
        $user = $this->currentUser($request);

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'portal_id' => $this->currentPortalId($request),
            // 見 6.11 第 4 點：跳轉登入的 session 帶 issued_via=hq_impersonation，
            // 讓子站後台可以顯示提示，避免操作人員忘記自己是用最高權限帳號在動作。
            'issued_via' => $request->attributes->get('jwt_session')?->issued_via,
        ]);
    }

    /**
     * @api-method PUT
     * @api-path /api/user/profile
     * @api-tag Auth
     * @api-summary 編輯自己的個人資料
     * @api-description
     * 只能改 `name`／`email`，不能改 `username`（登入帳號本身是識別碼，
     * 見 README 7 節，改 username 屬於帳號管理範疇，不開放使用者自己改）。
     * @api-portal *
     * @api-request-field name string 姓名
     * @api-request-field email string 電子郵件，可省略
     * @api-request-example
     * {"name": "王小明", "email": "wang@example.com"}
     * @api-response-field data.id int 使用者ID
     * @api-response-field data.name string 姓名
     * @api-response-field data.email string 電子郵件
     */
    public function updateProfile(Request $request)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
        ]);

        $user = $this->auth->updateProfile($this->currentUser($request), $data['name'], $data['email'] ?? null, $request->ip());

        return $this->success(['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
    }

    /**
     * @api-method PUT
     * @api-path /api/user/password
     * @api-tag Auth
     * @api-summary 修改自己的密碼
     * @api-description
     * 必須帶目前密碼（`current_password`）驗證身份，跟
     * `PUT /api/users/{user}/password`（管理員重設別人的密碼，不需要對方
     * 的舊密碼）是不同的端點——那支是端口管理員在管理他人帳號，這支是
     * 使用者自己改自己的密碼，安全性要求不同。
     * @api-portal *
     * @api-request-field current_password string 目前密碼
     * @api-request-field password string 新密碼
     * @api-request-example
     * {"current_password": "OldPass123", "password": "NewPass456"}
     * @api-response-empty
     */
    public function changePassword(Request $request)
    {
        $data = $this->validateInput($request, [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $result = $this->auth->changePassword($this->currentUser($request), $data['current_password'], $data['password'], $request->ip());

        if (! $result['ok']) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.auth.current_password_incorrect'));
        }

        return $this->success();
    }
}
