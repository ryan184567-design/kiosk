<?php

/* *****************************************
File: FactoryGuestApplyController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 訪客機專用的訪客預約接口（App 認證 + scope 授權），業務邏輯沿用 GuestApplyService
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Models\GuestSlot;

use App\Api\Guest\Services\GuestApplyService;
use App\Api\Guest\Services\GuestSlotService;

use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。
class FactoryGuestApplyController extends BaseApiController
{
    use MapsServiceExceptions;
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/factory/guest/applies
     * @api-tag Factory
     * @api-summary 以速通碼查詢訪客預約記錄
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.read
     * @api-description
     * 訪客機以速通碼查詢「今天、已通知」的預約單，只回傳訪客機需要的欄位。
     * 查無資料（速通碼錯誤、非今天、狀態不符、已取消）回 404。
     * 查詢參數（query string）：`fast_pass_code`（必填，速通碼），例如 `GET /api/factory/guest/applies?fast_pass_code=12345678`。
     * @api-query-field fast_pass_code int 速通碼
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.host_name string 受訪人
     * @api-response-field data.host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.visitor array 來訪者明細
     * @api-response-field data.visitor[].id int 來訪者ID
     * @api-response-field data.visitor[].guest_name string 來訪者姓名
     * @api-response-field data.visitor[].guest_company_name string 公司名稱，未填為 null
     * @api-response-field data.visitor[].guest_phone string 手機號碼
     */
    public function index(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'fast_pass_code' => 'required|string|max:20',
        ]);

        try {
            $apply = $service->check($data['fast_pass_code']);
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($this->present($apply));
    }


    /**
     * @api-method POST
     * @api-path /api/factory/guest/create
     * @api-tag Factory
     * @api-summary 現場訪客機新增訪客預約
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.create
     * @api-description
     * 建立一筆現場預約。單號、QR code、速通碼由系統產生，不接受外部傳入；
     * 新增後審核狀態為 10（已審核），預約類型固定為 1（現場預約）。
     * - `visit_date`、`visit_start_time`、`visit_end_time`、`guest_count`、`host_name`、`remark`
     * - `is_email_notify`、`is_sms_notify`、`is_auto_remind`、`remind_time`皆為系統寫入
     * @api-request-field doorplate_id int 受訪公司（門牌）ID
     * @api-request-field visit_reason string 拜訪事由，必填
     * @api-request-field visitor array 來訪者名單
     * @api-request-field visitor[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitor[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitor[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitor[].email string Email，必填，需為合法格式，最長 150 字
     * @api-request-example {"doorplate_id":12,"visit_reason":"業務洽談", "visitor":[{"name":"王小明","company_name":"宏昇工程股份有限公司","phone":"0912-345-678","email":"wang@hseng.com.tw"}]}
     * @api-response-empty
     */
    public function create(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'doorplate_id' => ['required', 'integer', 'exists:site_current.doorplates,id'],
            'visit_reason' => ['required', 'string', 'max:255'],
            'visitor' => ['required', 'array', 'min:1', 'max:10'],
            'visitor.*.name' => ['required', 'string', 'max:100'],
            'visitor.*.company_name' => ['required', 'string', 'max:150'],
            'visitor.*.phone' => ['required', 'string', 'regex:/^[0-9+\-]{8,30}$/'],
            'visitor.*.email' => ['required', 'email', 'max:150'],
        ]);

        $now = now();
        $oneHourLater = $now->copy()->addHour();

        $data['visit_date'] = $now->toDateString();
        $data['visit_start_time'] = $now->format('H:i');
        $data['visit_end_time'] = $oneHourLater->isSameDay($now) ? $oneHourLater->format('H:i') : '23:59';
        $data['guest_count'] = count($data['visitor']);
        $data['host_name'] = '';
        $data['remark'] = '';
        $data['is_email_notify'] = 1;
        $data['is_sms_notify'] = 1;
        $data['is_auto_remind'] = 0;
        $data['remind_time'] = 0;
        $data['reservation_type'] = GuestApply::TYPE_ONSITE;
        $data['reservation_status'] = GuestApply::STATUS_APPROVED;

        $guestApply = $service->create(
            $data['doorplate_id'],
            $data['visit_date'],
            $data['visit_start_time'],
            $data['visit_end_time'],
            $data['guest_count'],
            $data['host_name'],
            $data['visit_reason'],
            $data['remark'],
            $data['is_email_notify'],
            $data['is_sms_notify'],
            $data['is_auto_remind'],
            $data['remind_time'],
            $data['visitor'],
            $data['reservation_type'],
            $data['reservation_status'],
        );

        return $this->success();
    }

    /**
     * @api-method POST
     * @api-path /api/factory/guest/done
     * @api-tag Factory
     * @api-summary 確認訪客後寫入資料
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.done
     * @api-description
     * 訪客在訪客機報到後，寫入登錄的欄位資訊，並回傳門禁卡號與訪客人數
     * 查無資料（預約單查詢不到，狀態不符、證件櫃使用中）回 404。
     * @api-request-field id int 預約單ID
     * @api-request-field slot int 證件櫃ID
     * @api-request-field consent_form int 訪客同意書
     * @api-request-example {"id": 1, "slot": 2, "consent_form", 1}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.access_card_no string 門禁卡號
     */
    public function done(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'id' => 'required|int|max:10',
            'slot' => 'required|int|max:10',
            'consent_form' => 'required|int|max:1',
        ]);

        try {
            $apply = $service->checkDone(
                $data['id'],
                $data['slot'],
                $data['consent_form'],
            );
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($this->presentDone($apply), 201);
    }

    /**
     * @api-method POST
     * @api-path /api/factory/guest/leave
     * @api-tag Factory
     * @api-summary 訪客機訪客離場
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.leave
     * @api-description
     * 訪客在訪客機辦離場，使用門禁卡號比對已到的預約單，並將門禁卡號、速通碼、證件櫃清除
     * 查無資料（預約單查詢不到，證件櫃查詢不到）回 404。
     * @api-request-field card_no int 門禁卡號
     * @api-request-example {"card_no": 2548932547}
     * @api-response-empty
     */
    public function leave(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'card_no' => 'required|int|max:20',
        ]);

        try {
            $service->leave($data['card_no']);
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/factory/guest/slot
     * @api-tag Factory
     * @api-summary 證件櫃列表
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.slot
     * @api-description
     * 取得全部證件櫃及其目前狀態，供現場訪客機顯示可選擇的證件櫃。
     * 狀態以呼叫當下為準；實際報到時系統仍會再次檢查，已被占用的證件櫃會回傳 HTTP 404。
     * 查詢參數（query string）：`status`（選填，使用狀態），例如 `GET /api/factory/guest/slot?status=1`。
     * @api-dict guest_slot_status 0=空閒,1=使用中
     * @api-query-field page integer optional 頁碼（預設 1）
     * @api-query-field per_page integer optional 每頁筆數，-1 代表全部，未傳則使用配置值
     * @api-query-field status integer optional 使用狀態，0為未使用，1為使用中，未傳則回傳全部
     * @api-response-field data[].id int 證件櫃ID
     * @api-response-field data[].name string 證件櫃名稱
     * @api-response-field data[].status int dict:guest_slot_status 證件櫃狀態
     */
    public function slot(Request $request, GuestSlotService $slot)
    {
        $data = $this->validateInput($request, [
            'slot' => 'nullable|integer|in:0,1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? -1);

        $result = $this->paginateOrGet(
            $slot->slots($data['slot'] ?? null),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestSlot $g) => $this->presentSlot($g))->all();

        return $this->success($result);
    }

    private function present(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'host_name' => $apply->host_name,
            'host_company_name' => $apply->doorplate?->company_name,
            'guest_count' => $apply->guest_count,
            'visitor' => $apply->guests->map(fn (Guest $g) => [
                'id' => $g->id,
                'guest_name' => $g->name,
                'guest_company_name' => $g->company_name,
                'guest_phone' => $g->phone,
            ])->all(),
        ];
    }

    /** 訪客機完成報到 */
    private function presentDone(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'guest_count' => $apply->guest_count,
            'access_card_no' => $apply->access_card_no
        ];
    }

    private function presentSlot(GuestSlot $guestSlot): array
    {
        return [
            'id' => $guestSlot->id,
            'name' => $guestSlot->name,
            'status' => $guestSlot->status,
        ];
    }
}
