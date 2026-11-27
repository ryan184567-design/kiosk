<?php

/* *****************************************
File: GuestCompanyAdminController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 後台（admin 端口）公司帳號管理：各公司（門牌）審核設定、公司管理員／員工帳號。
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\GuestCompanyMember;
use App\Api\Guest\Services\GuestCompanyService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

class GuestCompanyAdminController extends BaseApiController
{
    use MapsServiceExceptions, PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/guest/companies
     * @api-tag GuestCompanyAdmin
     * @api-summary 公司列表
     * @api-description
     * 列出所有有公司名稱的門牌（一個門牌＝一家公司），含審核設定與成員數，依樓層、門牌排序。
     * 公司名稱在「門牌設定」維護；沒有公司名稱的門牌不會出現在這裡。
     * - `keyword` 比對公司名稱（模糊查詢）
     * - `per_page` 傳 -1 代表不分頁；省略時預設 10
     * @api-portal admin
     * @api-query-field keyword string optional 公司名稱關鍵字
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部
     * @api-response-field data.items[].doorplate_id int 公司（門牌）ID
     * @api-response-field data.items[].company_name string 公司名稱
     * @api-response-field data.items[].floor int 樓層
     * @api-response-field data.items[].doorplate int 門牌號碼
     * @api-response-field data.items[].approval_required bool 員工新增預約是否需要審核
     * @api-response-field data.items[].member_count int 成員帳號數
     * @api-response-field data.items[].admin_count int 公司管理員帳號數（0 代表這家公司還不能登入公司端口管理）
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function companyIndex(Request $request, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, [
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->companies($data['keyword'] ?? null),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn ($doorplate) => $service->presentCompany($doorplate) + [
            'member_count' => (int) $doorplate->member_count,
            'admin_count' => (int) $doorplate->admin_count,
        ])->all();

        return $this->success($result);
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/companies/{doorplateId}/setting
     * @api-tag GuestCompanyAdmin
     * @api-summary 修改公司審核設定
     * @api-description
     * 後台替某家公司設定「員工從公司端口新增的訪客預約」是否需要審核（公司管理員也可以在公司端口自行修改）；其他來源的訪客申請一律需要審核，不受此開關影響。
     * - `approval_required=true`：員工新增或修改預約後為待審核，由該公司的公司管理員或審核者（或後台）審核
     * - `approval_required=false`：員工新增預約直接為已核准（10）、修改後直接為修改已核准（11）
     * @api-portal admin
     * @api-request-field approval_required bool 是否需要審核，必填
     * @api-request-example {"approval_required": true}
     * @api-response-field data.doorplate_id int 公司（門牌）ID
     * @api-response-field data.company_name string 公司名稱
     * @api-response-field data.floor int 樓層
     * @api-response-field data.doorplate int 門牌號碼
     * @api-response-field data.approval_required bool 是否需要審核
     */
    public function companyApprovalUpdate(Request $request, int $doorplateId, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, ['approval_required' => 'required|boolean']);

        try {
            $doorplate = $service->updateApprovalRequired(
                $this->currentUser($request),
                $service->findCompany($doorplateId),
                (bool) $data['approval_required'],
                $request->ip(),
            );
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($service->presentCompany($doorplate));
    }

    /**
     * @api-method GET
     * @api-path /api/guest/companies/{doorplateId}/members
     * @api-tag GuestCompanyAdmin
     * @api-summary 公司成員列表
     * @api-description
     * 列出某家公司的所有成員帳號，依身分、建立順序排序。
     * - `keyword` 比對帳號、姓名（模糊查詢）
     * - `per_page` 傳 -1 代表不分頁；省略時預設 10
     * @api-portal admin
     * @api-dict guest_company_member_type 1=公司管理員,2=審核者,3=一般員工
     * @api-query-field keyword string optional 搜尋關鍵字
     * @api-query-field member_type int optional 身分篩選：1、2、3
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部
     * @api-response-field data.items[].id int 成員ID
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
    public function companyMemberIndex(Request $request, int $doorplateId, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, [
            'keyword' => 'nullable|string|max:100',
            'member_type' => 'nullable|int|in:1,2,3',
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        try {
            $doorplate = $service->findCompany($doorplateId);
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->members($doorplate->id, $data['keyword'] ?? null, isset($data['member_type']) ? (int) $data['member_type'] : null),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestCompanyMember $m) => $service->present($m))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/guest/companies/{doorplateId}/members
     * @api-tag GuestCompanyAdmin
     * @api-summary 新增公司帳號
     * @api-description
     * 替某家公司建立帳號，直接開通公司端口並歸屬該公司。系統給每家公司的「公司帳號」就是用這支建立
     * `member_type=1`（公司管理員）；公司管理員登入公司端口後，可自行新增審核者與一般員工。
     * 帳號全站唯一，已被使用會回錯誤。
     * @api-portal admin
     * @api-dict guest_company_member_type 1=公司管理員,2=審核者,3=一般員工
     * @api-request-field username string 登入帳號，必填，英數與底線、減號、點，3～50 字
     * @api-request-field password string 密碼，必填，8～100 字
     * @api-request-field name string 姓名，必填，最長 100 字
     * @api-request-field email string Email，選填
     * @api-request-field member_type int 身分，必填：1 公司管理員、2 審核者、3 一般員工
     * @api-request-example {"username":"acme.admin","password":"Passw0rd!","name":"宏昇公司管理員","member_type":1}
     * @api-response-field data.id int 成員ID
     * @api-response-field data.user_id int 使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 姓名
     * @api-response-field data.email string Email
     * @api-response-field data.member_type dict:guest_company_member_type 公司內身分
     * @api-response-field data.created_at datetime 建立時間
     */
    public function companyMemberStore(Request $request, int $doorplateId, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, [
            'username' => 'required|string|min:3|max:50|regex:/^[A-Za-z0-9_.\-]+$/',
            'password' => 'required|string|min:8|max:100',
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:150',
            'member_type' => 'required|int|in:1,2,3',
        ]);

        try {
            $member = $service->createMember(
                $this->currentUser($request),
                $service->findCompany($doorplateId),
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

        return $this->success($service->present($member), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/companies/{doorplateId}/members/{id}
     * @api-tag GuestCompanyAdmin
     * @api-summary 修改公司帳號
     * @api-description
     * 修改公司成員的姓名、Email 與身分。每家公司至少要保留一位公司管理員，最後一位不能改成其他身分。
     * 身分變更後對方需要重新登入。`id` 為成員ID。
     * @api-portal admin
     * @api-dict guest_company_member_type 1=公司管理員,2=審核者,3=一般員工
     * @api-request-field name string 姓名，必填，最長 100 字
     * @api-request-field email string Email，選填
     * @api-request-field member_type int 身分，必填：1、2、3
     * @api-request-example {"name":"宏昇公司管理員","email":null,"member_type":1}
     * @api-response-field data.id int 成員ID
     * @api-response-field data.user_id int 使用者ID
     * @api-response-field data.username string 登入帳號
     * @api-response-field data.name string 姓名
     * @api-response-field data.email string Email
     * @api-response-field data.member_type dict:guest_company_member_type 公司內身分
     * @api-response-field data.created_at datetime 建立時間
     */
    public function companyMemberUpdate(Request $request, int $doorplateId, int $id, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'email' => 'nullable|email|max:150',
            'member_type' => 'required|int|in:1,2,3',
        ]);

        try {
            $member = $service->findMember($doorplateId, $id);
            $updated = $service->updateMember($this->currentUser($request), $member, $data['name'], $data['email'] ?? null, (int) $data['member_type'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($service->present($updated));
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/companies/{doorplateId}/members/{id}/password
     * @api-tag GuestCompanyAdmin
     * @api-summary 重設公司帳號密碼
     * @api-description
     * 後台替公司成員（含公司管理員）重設密碼，不需要舊密碼。`id` 為成員ID。
     * @api-portal admin
     * @api-request-field password string 新密碼，必填，8～100 字
     * @api-request-example {"password":"NewPassw0rd!"}
     * @api-response-empty
     */
    public function companyMemberResetPassword(Request $request, int $doorplateId, int $id, GuestCompanyService $service)
    {
        $data = $this->validateInput($request, ['password' => 'required|string|min:8|max:100']);

        try {
            $service->resetMemberPassword($this->currentUser($request), $service->findMember($doorplateId, $id), $data['password'], $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/guest/companies/{doorplateId}/members/{id}
     * @api-tag GuestCompanyAdmin
     * @api-summary 移除公司帳號
     * @api-description
     * 移除公司成員：收回公司端口登入權限並讓已登入的 session 失效；帳號與他建立過的預約單保留。
     * 每家公司至少要保留一位公司管理員，最後一位不能移除。`id` 為成員ID。
     * @api-portal admin
     * @api-no-request-body
     * @api-response-empty
     */
    public function companyMemberDestroy(Request $request, int $doorplateId, int $id, GuestCompanyService $service)
    {
        try {
            $service->removeMember($this->currentUser($request), $service->findMember($doorplateId, $id), $request->ip());
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }
}
