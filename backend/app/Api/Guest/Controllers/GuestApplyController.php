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
    public function index()
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
     * 新增後審核狀態固定為 0（待審核），預約類型固定為 1（事前預約）。
     * - `time` 為單一字串，格式 `HH:mm~HH:mm`，結束時間必須晚於開始時間
     * - `date` 不可早於今天
     * - `visitors` 至少一位；名單人數不可超過 `visitor_count`（`visitor_count` 可大於名單，代表其餘同行者不另外登記）
     * - 通知設定目前只儲存開關，尚不會實際發送 Email／簡訊或排程提醒
     * - `reminder_enabled` 為 true 時 `reminder_time` 必填；為 false 時 `reminder_time` 會被忽略並存為 null
     * @api-dict guest_apply_audit_status 0=待審核,1=核准,2=駁回,3=已取消
     * @api-dict guest_apply_booking_type 1=事前預約,2=現場預約
     * @api-request-field doorplate_id int 受訪公司（門牌）ID，必填，需為已存在的門牌
     * @api-request-field date string 預約日期 YYYY-MM-DD，必填，不可早於今天
     * @api-request-field time string 預約時段 HH:mm~HH:mm，必填，例如 10:00~11:00
     * @api-request-field visitor_count int 來訪人數，必填，1～50
     * @api-request-field host_name string 受訪人，必填，最長 100 字
     * @api-request-field purpose string 預約事由，選填，最長 255 字，例如 業務洽談
     * @api-request-field notes string 備註，選填，最長 200 字
     * @api-request-field email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field reminder_enabled bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field reminder_time int 到訪前幾分鐘提醒，reminder_enabled 為 true 時必填，1～10080
     * @api-request-field visitors array 來訪者名單，必填，1～50 位
     * @api-request-field visitors[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitors[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitors[].title string 職稱，選填，最長 100 字
     * @api-request-field visitors[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitors[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitors[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"date":"2026-11-20","time":"10:00~11:00","visitor_count":1,"host_name":"陳志明","purpose":"業務洽談","notes":"","email_notify":true,"sms_notify":true,"reminder_enabled":true,"reminder_time":30,"visitors":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.applies_no string 預約單號，格式 GA{YYYYMMDD}{4位流水號}
     * @api-response-field data.qr_code string 系統產生的 QR code 驗證碼
     * @api-response-field data.visit_code string 系統產生的速通碼（8 位數字）

     */
    public function store(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, $this->rules());

        $guestApply = $service->create(
            $data['doorplate_id'],
            $data['date'],
            $data['time'],
            $data['visitor_count'],
            $data['host_name'],
            $data['purpose'],
            $data['notes'],
            $data['email_notify'],
            $data['sms_notify'],
            $data['reminder_enabled'],
            $data['reminder_time'],
            $data['visitors']
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
     * 修改預約單內容，欄位規則與新增相同；`visitors` 為整批覆寫（原名單會被取代），所以要保留的來訪者也必須一併送出。
     * 單號、QR code、速通碼、審核狀態都不會因修改而改變。
     * 只有尚未報到的預約單可以修改
     * @api-dict guest_apply_audit_status 0=待審核,1=核准,2=駁回,3=已取消
     * @api-dict guest_apply_booking_type 1=事前預約,2=現場預約
     * @api-request-field id int 預約單ID（路徑參數）
     * @api-request-field doorplate_id int 受訪公司（門牌）ID，必填，需為已存在的門牌
     * @api-request-field date string 預約日期 YYYY-MM-DD，必填，不可早於今天
     * @api-request-field time string 預約時段 HH:mm~HH:mm，必填，例如 10:00~11:00
     * @api-request-field visitor_count int 來訪人數，必填，1～50
     * @api-request-field host_name string 受訪人，必填，最長 100 字
     * @api-request-field purpose string 預約事由，選填，最長 255 字
     * @api-request-field notes string 備註，選填，最長 200 字
     * @api-request-field email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field reminder_enabled bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field reminder_time int 到訪前幾分鐘提醒，reminder_enabled 為 true 時必填，1～10080
     * @api-request-field visitors array 來訪者名單（整批覆寫），必填，1～50 位
     * @api-request-field visitors[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitors[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitors[].title string 職稱，選填，最長 100 字
     * @api-request-field visitors[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitors[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitors[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"date":"2026-11-21","time":"14:00~15:30","visitor_count":2,"host_name":"陳志明","purpose":"業務洽談","notes":"改約下午","email_notify":true,"sms_notify":false,"reminder_enabled":true,"reminder_time":60,"visitors":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"},{"name":"李大華","company_name":"宏昇工程股份有限公司","phone":"0933-111-222"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.applies_no string 預約單號
     * @api-response-field data.qr_code string QR code 驗證碼（修改不會重新產生）
     * @api-response-field data.visit_code string 速通碼（修改不會重新產生）
     */
    public function update(int $id, Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, $this->rules());

        $guestApply = $service->update(
            $id,
            $data['doorplate_id'],
            $data['date'],
            $data['time'],
            $data['visitor_count'],
            $data['host_name'],
            $data['purpose'],
            $data['notes'],
            $data['email_notify'],
            $data['sms_notify'],
            $data['reminder_enabled'],
            $data['reminder_time'],
            $data['visitors'],
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
     * 取消預約單：審核狀態改為 3（已取消），並清除 QR code 與速通碼使其立即失效。
     * 這是狀態變更而非刪除，預約單與來訪者資料都會保留，已取消的預約單不可再修改或再次取消。
     * 只有審核狀態為 0（待審核）或 1（核准）、且尚未報到的預約單可以取消：
     * @api-dict guest_apply_audit_status 0=待審核,1=核准,2=駁回,3=已取消
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
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d~([01]\d|2[0-3]):[0-5]\d$/'],
            'visitor_count' => ['required', 'integer', 'min:1', 'max:50'],
            'host_name' => ['required', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:200'],
            'email_notify' => ['nullable', 'boolean'],
            'sms_notify' => ['nullable', 'boolean'],
            'reminder_enabled' => ['nullable', 'boolean'],
            'reminder_time' => ['required_if:reminder_enabled,true,1', 'nullable', 'integer', 'min:1', 'max:10080'],
            'visitors' => ['required', 'array', 'min:1', 'max:50'],
            'visitors.*.name' => ['required', 'string', 'max:100'],
            'visitors.*.company_name' => ['required', 'string', 'max:150'],
            'visitors.*.title' => ['nullable', 'string', 'max:100'],
            'visitors.*.phone' => ['required', 'string', 'regex:/^[0-9+\-]{8,30}$/'],
            'visitors.*.email' => ['nullable', 'email', 'max:150'],
            'visitors.*.plate_number' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function presentGuestApply(GuestApply $guestapply): array
    {
        return [
            'applies_no' => $guestapply->applies_no,
            'qr_code' => $guestapply->qr_code,
            'visit_code' => $guestapply->visit_code,
        ];
    }
}