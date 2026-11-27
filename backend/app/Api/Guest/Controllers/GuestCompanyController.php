<?php

/* *****************************************
File: GuestCompanyController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口（company）：本公司資訊、審核設定、員工帳號管理。資料範圍一律是登入者所屬公司。
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\GuestCompanyMember;
use App\Api\Guest\Services\GuestCompanyService;
use App\Api\Guest\Support\GuestCompanyContext;
use App\Support\Api\ApiResponder;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 這支只給 company 端口用：路由掛在 EnsureGuestCompanyScope 之後，
// GuestCompanyContext::member() 一定有值；admin 端口呼叫會直接回權限不足。
class GuestCompanyController extends BaseApiController
{
    use MapsServiceExceptions, PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/guest/company
     * @api-tag GuestCompany
     * @api-summary 本公司資訊
     * @api-description
     * 公司端口登入後取得「我所屬的公司」與「我在公司內的身分」，前端依此決定畫面（例如審核者才顯示審核頁）。
     * 只能在公司端口（company）呼叫，其他端口回權限不足。
     * @api-portal company
     * @api-dict guest_company_member_type 1=公司管理員,2=審核者,3=一般員工
     * @api-no-request-body
     * @api-response-field data.doorplate_id int 公司（門牌）ID
     * @api-response-field data.company_name string 公司名稱
     * @api-response-field data.floor int 樓層
     * @api-response-field data.doorplate int 門牌號碼
     * @api-response-field data.approval_required bool 員工新增預約是否需要審核；false 時新增即自動核准
     * @api-response-field data.member_type dict:guest_company_member_type 登入者在公司內的身分
     */
    public function companyProfile(GuestCompanyService $service)
    {
        $member = $this->member();

        return $this->success($service->presentCompany($member->doorplate) + ['member_type' => $member->member_type]);
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/company/setting
     * @api-tag GuestCompany
     * @api-summary 修改本公司審核設定
     * @api-description
     * 設定本公司「員工從公司端口新增的訪客預約」是否需要審核；其他來源的訪客申請（後台建立等）一律需要審核，不受此開關影響。
     * - `approval_required=true`：員工新增或修改預約後為待審核，由公司管理員或審核者審核
     * - `approval_required=false`：員工新增預約直接為已核准（10）、修改後直接為修改已核准（11）
     * 設定只影響之後新增／修改的預約，已存在的待審核預約仍需審核。只有公司管理員可以修改。
     * @api-portal company
     * @api-request-field approval_required bool 是否需要審核，必填
     * @api-request-example {"approval_required": false}
     * @api-response-field data.doorplate_id int 公司（門牌）ID
     * @api-response-field data.company_name string 公司名稱
     * @api-response-field data.floor int 樓層
     * @api-response-field data.doorplate int 門牌號碼
     * @api-response-field data.approval_required bool 是否需要審核
     */
    public function companySettingUpdate(Request $request, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        $data = $this->validateInput($request, ['approval_required' => 'required|boolean']);

        $doorplate = $service->updateApprovalRequired($this->currentUser($request), $member->doorplate, (bool) $data['approval_required'], $request->ip());

        return $this->success($service->presentCompany($doorplate));
    }

    /**
     * @api-method GET
     * @api-path /api/guest/company/employees
     * @api-tag GuestCompany
     * @api-summary 本公司員工列表
     * @api-description
     * 列出本公司所有成員帳號（含公司管理員自己），依身分、建立順序排序。只有公司管理員可以查看。
     * - `keyword` 比對帳號、姓名（模糊查詢）
     * - `per_page` 傳 -1 代表不分頁；省略時預設 10
     * @api-portal company
     * @api-dict guest_company_member_type 1=公司管理員,2=審核者,3=一般員工
     * @api-query-field keyword string optional 搜尋關鍵字
     * @api-query-field member_type int optional 身分篩選：1、2、3
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部
     * @api-response-field data.items[].id int 成員ID（修改、刪除用這個）
     * @api-response-field data.items[].user_id int 使用者ID
     * @api-response-field data.items[].username string 登入帳號
     * @api-response-field data.items[].name string 姓名
     * @api-response-field data.items[].email string Email，未填為 null
     * @api-response-field data.items[].member_type dict:guest_company_member_type 公司內身分
     * @api-response-field data.items[].created_at datetime 建立時間
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function employeeIndex(Request $request, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        $data = $this->validateInput($request, [
            'keyword' => 'nullable|string|max:100',
            'member_type' => 'nullable|int|in:1,2,3',
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->members($member->doorplate_id, $data['keyword'] ?? null, isset($data['member_type']) ? (int) $data['member_type'] : null),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestCompanyMember $m) => $service->present($m))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/guest/company/employees
     * @api-tag GuestCompany
     * @api-summary 新增本公司員工
     * @api-description
     * 建立一個新的員工帳號，直接開通公司端口並歸屬本公司。只有公司管理員可以新增。
     * - `member_type` 只能是 2（審核者）或 3（一般員工）；公司管理員帳號由後台建立
     * - 審核者可以審核本公司的訪客預約；一般員工只能登記與管理預約
     * - 帳號全站唯一，已被使用會回錯誤
     * @api-portal company
     * @api-dict guest_company_member_type 2=審核者,3=一般員工
     * @api-request-field username string 登入帳號，必填，英數與底線、減號、點，3～50 字
     * @api-request-field password string 密碼，必填，8～100 字
     * @api-request-field name string 姓名，必填，最長 100 字
     * @api-request-field email string Email，選填
     * @api-request-field member_type int 身分，必填：2 審核者、3 一般員工
     * @api-request-example {"username":"amy.chen","password":"Passw0rd!","name":"陳小美","email":"amy@example.com","member_type":3}
     * @api-response-field data.id int 成員ID
     * @api-response-field data.user_id int 使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 姓名
     * @api-response-field data.email string Email
     * @api-response-field data.member_type dict:guest_company_member_type 公司內身分
     * @api-response-field data.created_at datetime 建立時間
     */
    public function employeeStore(Request $request, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        $data = $this->validateInput($request, $this->accountRules(true) + [
            'member_type' => 'required|int|in:'.implode(',', GuestCompanyService::COMPANY_ASSIGNABLE_TYPES),
        ]);

        try {
            $created = $service->createMember(
                $this->currentUser($request),
                $member->doorplate,
                $data['username'],
                $data['password'],
                $data['name'],
                $data['email'] ?? null,
                (int) $data['member_type'],
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($service->present($created), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/company/employees/{id}
     * @api-tag GuestCompany
     * @api-summary 修改本公司員工
     * @api-description
     * 修改員工姓名、Email 與身分（審核者／一般員工互換＝設定審核者權限）。只有公司管理員可以修改，
     * 且不能修改其他公司管理員（含自己）。身分變更後對方需要重新登入。`id` 為成員ID。
     * @api-portal company
     * @api-dict guest_company_member_type 2=審核者,3=一般員工
     * @api-request-field name string 姓名，必填，最長 100 字
     * @api-request-field email string Email，選填
     * @api-request-field member_type int 身分，必填：2 審核者、3 一般員工
     * @api-request-example {"name":"陳小美","email":"amy@example.com","member_type":2}
     * @api-response-field data.id int 成員ID
     * @api-response-field data.user_id int 使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 姓名
     * @api-response-field data.email string Email
     * @api-response-field data.member_type dict:guest_company_member_type 公司內身分
     * @api-response-field data.created_at datetime 建立時間
     */
    public function employeeUpdate(Request $request, int $id, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:150',
            'member_type' => 'required|int|in:'.implode(',', GuestCompanyService::COMPANY_ASSIGNABLE_TYPES),
        ]);

        try {
            $target = $service->findMember($member->doorplate_id, $id);
            $service->assertManageableByCompanyAdmin($target);

            $updated = $service->updateMember($this->currentUser($request), $target, $data['name'], $data['email'] ?? null, (int) $data['member_type'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($service->present($updated));
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/company/employees/{id}/password
     * @api-tag GuestCompany
     * @api-summary 重設本公司員工密碼
     * @api-description
     * 公司管理員替員工（審核者、一般員工）重設密碼，不需要舊密碼。`id` 為成員ID。
     * @api-portal company
     * @api-request-field password string 新密碼，必填，8～100 字
     * @api-request-example {"password":"NewPassw0rd!"}
     * @api-response-empty
     */
    public function employeeResetPassword(Request $request, int $id, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        $data = $this->validateInput($request, ['password' => 'required|string|min:8|max:100']);

        try {
            $target = $service->findMember($member->doorplate_id, $id);
            $service->assertManageableByCompanyAdmin($target);
            $service->resetMemberPassword($this->currentUser($request), $target, $data['password'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/guest/company/employees/{id}
     * @api-tag GuestCompany
     * @api-summary 移除本公司員工
     * @api-description
     * 移除員工：收回公司端口登入權限並讓已登入的 session 失效；帳號與他建立過的預約單保留。
     * 只有公司管理員可以移除，不能移除公司管理員。`id` 為成員ID。
     * @api-portal company
     * @api-no-request-body
     * @api-response-empty
     */
    public function employeeDestroy(Request $request, int $id, GuestCompanyService $service)
    {
        $member = $this->member();
        $this->assertCompanyAdmin($member);

        try {
            $target = $service->findMember($member->doorplate_id, $id);
            $service->assertManageableByCompanyAdmin($target);
            $service->removeMember($this->currentUser($request), $target, $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    private function member(): GuestCompanyMember
    {
        $member = GuestCompanyContext::member();

        if (! $member) {
            abort(ApiResponder::fail(ErrorCode::PERMISSION_DENIED, __('api.guest_company.company_portal_only')));
        }

        return $member;
    }

    // 權限碼已由角色控管（一般員工、審核者的角色沒有這些碼），這裡再以公司內身分把關一次：
    // 避免後台有人手動把這些碼勾給其他角色時，非公司管理員也能管理員工。
    private function assertCompanyAdmin(GuestCompanyMember $member): void
    {
        if ($member->member_type !== GuestCompanyMember::TYPE_ADMIN) {
            abort(ApiResponder::fail(ErrorCode::PERMISSION_DENIED, __('api.guest_company.company_admin_only')));
        }
    }

    private function accountRules(bool $withPassword): array
    {
        return [
            'username' => 'required|string|min:3|max:50|regex:/^[A-Za-z0-9_.\-]+$/',
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:150',
        ] + ($withPassword ? ['password' => 'required|string|min:8|max:100'] : []);
    }
}
