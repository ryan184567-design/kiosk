<?php

namespace App\Api\Guest\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Support\Api\Concerns\PaginationHelper;
use App\Api\Guest\Services\GuestApplyService;
use Illuminate\Http\Request;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;

class GuestApplyController extends BaseApiController
{
    use PaginationHelper;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, GuestApplyService $service)
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * @api-method POST
     * @api-path /api/guest/applies
     * @api-tag 訪客預約
     * @api-summary 新增訪客預約
     * @api-portal admin
     * @api-description
     * 建立一筆事前預約。單號、QR code、速通碼由系統產生，不接受外部傳入；
     * 新增後審核狀態固定為 0（待審核），預約類型固定為 0（事前預約）。
     * - `date` 不可早於今天
     * - `visitor` 至少一位；名單人數不可超過 `guest_count`（`guest_count` 可大於名單，代表其餘同行者不另外登記）
     * - 通知設定目前只儲存開關，尚不會實際發送 Email／簡訊或排程提醒
     * - `is_auto_remind` 為 true 時 `remind_time` 必填；為 false 時 `remind_time` 會被忽略並存為 null
     * @api-dict guest_apply_reservation_status 0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消
     * @api-dict guest_apply_reservation_type 0:事前預約,1:現場預約
     * @api-request-field doorplate_id int 受訪公司（門牌）ID，必填，需為已存在的門牌
     * @api-request-field visit_date string 預約日期 YYYY-MM-DD，必填，不可早於今天
     * @api-request-field visit_start_time string 預約時段(起始) HH:mm，必填，例如 10:00
     * @api-request-field visit_end_time string 預約時段(結束) HH:mm，必填，例如 11:00
     * @api-request-field guest_count int 來訪人數，必填，1～10
     * @api-request-field host_name string 受訪人，必填，最長 100 字
     * @api-request-field visit_reason string 預約事由，選填，最長 255 字
     * @api-request-field remark string 備註，選填，最長 200 字
     * @api-request-field is_email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field is_sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field is_auto_remind bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field remind_time int 到訪前幾分鐘提醒，reminder_enabled 為 true 時必填，1～10080
     * @api-request-field visitor array 來訪者名單，必填，1～10 位
     * @api-request-field visitor[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitor[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitor[].title string 職稱，選填，最長 100 字
     * @api-request-field visitor[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitor[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitor[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"visit_date":"2026-11-21","visit_start_time":"14:00","visit_end_time":"15:00","guest_count":2,"host_name":"陳志明","visit_reason":"業務洽談","remark":"改約下午","is_email_notify":true,"is_sms_notify":false,"is_auto_remind":true,"remind_time":60,"visitor":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"},{"name":"李大華","company_name":"宏昇工程股份有限公司","phone":"0933-111-222"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.qr_code string QR code 驗證碼（修改不會重新產生）
     * @api-response-field data.fast_pass_code string 速通碼（修改不會重新產生）

     */
    public function store(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, $this->rules());

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
            $data['visitor']
        );

        return $this->success($this->presentGuestApply($guestApply), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/applies/{id}
     * @api-tag 訪客預約
     * @api-summary 修改訪客預約
     * @api-portal admin
     * @api-description
     * 修改預約單內容，欄位規則與新增相同；`visitor` 為整批重寫（原名單會被刪除），所以要保留的來訪者也必須一併送出。
     * 單號、QR code、速通碼、審核狀態都不會因修改而改變。
     * 只有尚未報到的預約單可以修改
     * @api-dict guest_apply_reservation_status 0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消
     * @api-dict guest_apply_reservation_type 0:事前預約,1:現場預約
     * @api-request-field id int 預約單ID（路徑參數）
     * @api-request-field doorplate_id int 受訪公司（門牌）ID，必填，需為已存在的門牌
     * @api-request-field visit_date string 預約日期 YYYY-MM-DD，必填，不可早於今天
     * @api-request-field visit_start_time string 預約時段(起始) HH:mm，必填，例如 10:00
     * @api-request-field visit_end_time string 預約時段(結束) HH:mm，必填，例如 11:00
     * @api-request-field guest_count int 來訪人數，必填，1～10
     * @api-request-field host_name string 受訪人，必填，最長 100 字
     * @api-request-field visit_reason string 預約事由，選填，最長 255 字
     * @api-request-field remark string 備註，選填，最長 200 字
     * @api-request-field is_email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field is_sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field is_auto_remind bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field remind_time int 到訪前幾分鐘提醒，reminder_enabled 為 true 時必填，1～10080
     * @api-request-field visitor array 來訪者名單（整批覆寫），必填，1～10 位
     * @api-request-field visitor[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitor[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitor[].title string 職稱，選填，最長 100 字
     * @api-request-field visitor[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitor[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitor[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"visit_date":"2026-11-21","visit_start_time":"14:00","visit_end_time":"15:00","guest_count":2,"host_name":"陳志明","visit_reason":"業務洽談","remark":"改約下午","is_email_notify":true,"is_sms_notify":false,"is_auto_remind":true,"remind_time":60,"visitor":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"},{"name":"李大華","company_name":"宏昇工程股份有限公司","phone":"0933-111-222"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.qr_code string QR code 驗證碼（修改不會重新產生）
     * @api-response-field data.fast_pass_code string 速通碼（修改不會重新產生）
     */
    public function update(int $id, Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, $this->rules());

        $guestApply = $service->update(
            $id,
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
        );

        return $this->success($this->presentGuestApply($guestApply), 201);
    }


    /**
     * @api-method PUT
     * @api-path /api/guest/applies/{id}/cancel
     * @api-tag 訪客預約
     * @api-summary 取消訪客預約
     * @api-portal admin
     * @api-description
     * 取消預約單：審核狀態改為 60（已取消），並清除 QR code 與速通碼使其立即失效。
     * 這是狀態變更而非刪除，預約單與來訪者資料都會保留，已取消的預約單不可再修改或再次取消。
     * @api-dict guest_apply_reservation_status 0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消
     * @api-request-field id int 預約單ID（路徑參數）
     * @api-request-example {"id": 2}
     * @api-response-empty
     */
    public function cancel(int $id, Request $request, GuestApplyService $service)
    {
        $guestApply = $service->cancel($id);

        return $this->success();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    private function rules(): array
    {
        return [
            // 假設門牌表為 site_current 連線下的 doorplates；實際表名不同請改這一行
            'doorplate_id' => ['required', 'integer', 'exists:site_current.doorplates,id'],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'visit_start_time' => ['required', 'date_format:H:i'],
            'visit_end_time' => ['required', 'date_format:H:i', 'after:visit_start_time'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:50'],
            'host_name' => ['required', 'string', 'max:100'],
            'visit_reason' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:200'],
            'is_email_notify' => ['nullable', 'boolean'],
            'is_sms_notify' => ['nullable', 'boolean'],
            'is_auto_remind' => ['nullable', 'boolean'],
            'remind_time' => ['required_if:reminder_enabled,true,1', 'nullable', 'integer', 'min:1', 'max:10080'],
            'visitor' => ['required', 'array', 'min:1', 'max:50'],
            'visitor.*.name' => ['required', 'string', 'max:100'],
            'visitor.*.company_name' => ['required', 'string', 'max:150'],
            'visitor.*.title' => ['nullable', 'string', 'max:100'],
            'visitor.*.phone' => ['required', 'string', 'regex:/^[0-9+\-]{8,30}$/'],
            'visitor.*.email' => ['nullable', 'email', 'max:150'],
            'visitor.*.plate_number' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function presentGuestApply(GuestApply $guestapply): array
    {
        return [
            'reservation_id' => $guestapply->reservation_id,
            'qr_code' => $guestapply->qr_code,
            'fast_pass_code' => $guestapply->fast_pass_code,
        ];
    }
}
