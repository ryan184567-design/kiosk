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
     * @api-method GET
     * @api-path /api/guest/applies
     * @api-tag GuestApply
     * @api-summary 訪客預約列表
     * @api-description
     * 依日期、狀態與關鍵字查詢預約單，依預約日期與起始時間由新到舊排序。
     * - `keyword` 會比對預約單號、受訪人、來訪者姓名（模糊查詢）
     * - `date_from`／`date_to` 為預約日期區間（含頭尾），兩者皆可單獨使用
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * - 已取消（reservation_status=60）的預約單也會出現在列表，可用 `reservation_status` 篩選
     * @api-portal admin
     * @api-dict guest_apply_reservation_status 0=待審核,1=修改待審核,10=已核准,11=修改已核准,20=已通知,30=未到,40=已到,50=已離場,60=已取消
     * @api-dict guest_apply_reservation_type 0=事前預約,1=現場預約
     * @api-query-field keyword string optional 搜尋關鍵字
     * @api-query-field date_from int optional 起始日期 (YYYY-MM-DD)
     * @api-query-field date_to int optional 結束日期 (YYYY-MM-DD)
     * @api-query-field reservation_status int 預約單狀態
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部，未傳則使用配置值
     * @api-response-field data.items[].id int 預約單ID
     * @api-response-field data.items[].reservation_id string 預約單號
     * @api-response-field data.items[].reservation_type dict:guest_apply_reservation_type 預約類型
     * @api-response-field data.items[].reservation_status dict:guest_apply_reservation_status 預約單狀態
     * @api-response-field data.items[].host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.items[].host_floor string 受訪公司所在樓層，門牌不存在時為 null
     * @api-response-field data.items[].host_name string 受訪人
     * @api-response-field data.items[].visit_date string 預約日期 YYYY-MM-DD
     * @api-response-field data.items[].visit_start_time string 預約時段起 HH:mm
     * @api-response-field data.items[].visit_end_time string 預約時段迄 HH:mm
     * @api-response-field data.items[].guest_count int 訪客人數
     * @api-response-field data.items[].visit_reason int 來訪事由，必填
     * @api-response-field data.items[].check_in_at datetime 到場時間，尚未報到為 null
     * @api-response-field data.items[].check_out_at datetime 離場時間，尚未報到為 null
     * @api-response-field data.items[].visitor array 來訪者明細
     * @api-response-field data.items[].visitor[].id int 來訪者ID
     * @api-response-field data.items[].visitor[].guest_name string 來訪者姓名
     * @api-response-field data.items[].visitor[].guest_company_name string 公司名稱
     * @api-response-field data.items[].visitor[].guest_phone string 手機號碼
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function index(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'keyword' => 'nullable|string|max:100',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'reservation_status' => 'nullable|int|in:0,1,10,11,20,30,40,50,60',
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $service->applies(
                $data['keyword'] ?? null,
                $data['date_from'] ?? null,
                $data['date_to'] ?? null,
                isset($data['reservation_status']) ? (int) $data['reservation_status'] : null,
            ),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestApply $a) => $this->presentListItem($a))->all();

        return $this->success($result);
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
     * @api-tag GuestApply
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
     * @api-request-field visit_reason int 預約事由，必填
     * @api-request-field remark string 備註，選填，最長 200 字
     * @api-request-field is_email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field is_sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field is_auto_remind bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field remind_time int 到訪前幾分鐘提醒，is_auto_remind 為 true 時必填，1～10080
     * @api-request-field visitor array 來訪者名單，必填，1～10 位
     * @api-request-field visitor[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitor[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitor[].title string 職稱，選填，最長 100 字
     * @api-request-field visitor[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitor[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitor[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"visit_date":"2026-11-21","visit_start_time":"14:00","visit_end_time":"15:00","guest_count":2,"host_name":"陳志明","visit_reason": 1,"remark":"改約下午","is_email_notify":true,"is_sms_notify":false,"is_auto_remind":true,"remind_time":60,"visitor":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"},{"name":"李大華","company_name":"宏昇工程股份有限公司","phone":"0933-111-222"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.qr_code string QR code 驗證碼（修改不會重新產生）
     * @api-response-field data.fast_pass_code string 速通碼（修改不會重新產生）
     */
    public function store(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, $this->rules());

        $data['reservation_type'] = GuestApply::TYPE_ADVANCE;
        $data['reservation_status'] = GuestApply::STATUS_PENDING;

        $guestApply = $service->create(
            $data['doorplate_id'],
            $data['visit_date'],
            $data['visit_start_time'],
            $data['visit_end_time'],
            $data['guest_count'],
            $data['host_name'],
            $data['visit_reason'],
            $data['remark'] ?? '',
            $data['is_email_notify'] ?? 1,
            $data['is_sms_notify'] ?? 1,
            $data['is_auto_remind'] ?? 1,
            $data['remind_time'] ?? 30,
            $data['visitor'],
            $data['reservation_type'],
            $data['reservation_status'],
        );

        return $this->success($this->presentGuestApply($guestApply), 201);
    }

    /**
     * @api-method GET
     * @api-path /api/guest/applies/{id}
     * @api-tag GuestApply
     * @api-summary 訪客預約詳情
     * @api-description
     * 取得單筆預約單，含完整來訪者明細、通知設定、QR code、速通碼與門禁卡號。
     * 已取消的預約單 `qr_code`、`fast_pass_code` 為 null（取消時已清除）。
     * @api-portal admin
     * @api-dict guest_apply_reservation_status 0=待審核,1=修改待審核,10=已核准,11=修改已核准,20=已通知,30=未到,40=已到,50=已離場,60=已取消
     * @api-dict guest_apply_reservation_type 0=事前預約,1=現場預約
     * @api-no-request-body
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號，格式 GA{YYYYMMDD}{4位流水號}
     * @api-response-field data.reservation_type dict:guest_apply_reservation_type 預約類型
     * @api-response-field data.reservation_status dict:guest_apply_reservation_status 預約單狀態
     * @api-response-field data.host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.host_floor string 受訪公司所在樓層，門牌不存在時為 null
     * @api-response-field data.host_name string 受訪人
     * @api-response-field data.visit_date string 預約日期 YYYY-MM-DD
     * @api-response-field data.visit_start_time string 預約時段起 HH:mm
     * @api-response-field data.visit_end_time string 預約時段迄 HH:mm
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.visit_reason int 來訪事由，必填
     * @api-response-field data.remark string 備註，未填為 null
     * @api-response-field data.is_email_notify bool 是否 Email 通知受訪人
     * @api-response-field data.is_sms_notify bool 是否簡訊通知來訪者
     * @api-response-field data.is_auto_remind bool 是否到訪前自動提醒
     * @api-response-field data.remind_time int 到訪前幾分鐘提醒，未開啟提醒為 null
     * @api-response-field data.qr_code string QR code 驗證碼，已取消為 null
     * @api-response-field data.fast_pass_code string 速通碼（8 位數字），已取消為 null
     * @api-response-field data.access_card_no string 門禁卡號，尚未配發為 null
     * @api-response-field data.check_in_at datetime 到場時間，尚未報到為 null
     * @api-response-field data.check_out_at datetime 離場時間，尚未離場為 null
     * @api-response-field data.created_at datetime 建立時間
     * @api-response-field data.updated_at datetime 最後更新時間
     * @api-response-field data.visitor array 來訪者明細
     * @api-response-field data.visitor[].id int 來訪者ID
     * @api-response-field data.visitor[].name string 來訪者姓名
     * @api-response-field data.visitor[].company_name string 公司名稱，未填為 null
     * @api-response-field data.visitor[].title string 職稱，未填為 null
     * @api-response-field data.visitor[].phone string 手機號碼
     * @api-response-field data.visitor[].email string Email，未填為 null
     * @api-response-field data.visitor[].plate_number string 車牌號碼，未填為 null
     */
    public function show(int $id, GuestApplyService $service)
    {
        $apply = $service->show($id);

        return $this->success($this->presentApplyId($apply));
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
     * @api-tag GuestApply
     * @api-summary 修改訪客預約
     * @api-portal admin
     * @api-description
     * 修改預約單內容，欄位規則與新增相同；`visitor` 為整批重寫（原名單會被刪除），所以要保留的來訪者也必須一併送出。
     * 單號、QR code、速通碼、審核狀態都不會因修改而改變。
     * 只有尚未報到的預約單可以修改
     * @api-dict guest_apply_reservation_status 0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消
     * @api-dict guest_apply_reservation_type 0:事前預約,1:現場預約
     * @api-request-field doorplate_id int 受訪公司（門牌）ID，必填，需為已存在的門牌
     * @api-request-field visit_date string 預約日期 YYYY-MM-DD，必填，不可早於今天
     * @api-request-field visit_start_time string 預約時段(起始) HH:mm，必填，例如 10:00
     * @api-request-field visit_end_time string 預約時段(結束) HH:mm，必填，例如 11:00
     * @api-request-field guest_count int 來訪人數，必填，1～10
     * @api-request-field host_name string 受訪人，必填，最長 100 字
     * @api-request-field visit_reason int 預約事由，必填
     * @api-request-field remark string 備註，選填，最長 200 字
     * @api-request-field is_email_notify bool 是否 Email 通知受訪人，選填，預設 false
     * @api-request-field is_sms_notify bool 是否簡訊通知來訪者，選填，預設 false
     * @api-request-field is_auto_remind bool 是否到訪前自動提醒，選填，預設 false
     * @api-request-field remind_time int 到訪前幾分鐘提醒，is_auto_remind 為 true 時必填，1～10080
     * @api-request-field visitor array 來訪者名單（整批覆寫），必填，1～10 位
     * @api-request-field visitor[].name string 來訪者姓名，必填，最長 100 字
     * @api-request-field visitor[].company_name string 公司名稱，必填，最長 150 字
     * @api-request-field visitor[].title string 職稱，選填，最長 100 字
     * @api-request-field visitor[].phone string 手機號碼，必填，數字、加號與連字號，8～30 字元
     * @api-request-field visitor[].email string Email，選填，需為合法格式，最長 150 字
     * @api-request-field visitor[].plate_number string 車牌號碼，選填，最長 20 字
     * @api-request-example {"doorplate_id":12,"visit_date":"2026-11-21","visit_start_time":"14:00","visit_end_time":"15:00","guest_count":2,"host_name":"陳志明","visit_reason": 1,"remark":"改約下午","is_email_notify":true,"is_sms_notify":false,"is_auto_remind":true,"remind_time":60,"visitor":[{"name":"王小明","company_name":"宏昇工程股份有限公司","title":"業務經理","phone":"0912-345-678","email":"wang@hseng.com.tw","plate_number":"ABC-1234"},{"name":"李大華","company_name":"宏昇工程股份有限公司","phone":"0933-111-222"}]}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.qr_code string QR code 驗證碼（修改不會重新產生）
     * @api-response-field data.fast_pass_code string 速通碼（修改不會重新產生）
     */
    public function update(Request $request, int $id, GuestApplyService $service)
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
            $data['remark'] ?? '',
            $data['is_email_notify'] ?? 1,
            $data['is_sms_notify'] ?? 1,
            $data['is_auto_remind'] ?? 1,
            $data['remind_time'] ?? 30,
            $data['visitor'],
        );

        return $this->success($this->presentGuestApply($guestApply), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/applies/{id}/cancel
     * @api-tag GuestApply
     * @api-summary 取消訪客預約
     * @api-portal admin
     * @api-description
     * 取消預約單：審核狀態改為 60（已取消），並清除 QR code 與速通碼、門禁卡號使其立即失效。
     * 這是狀態變更而非刪除，預約單與來訪者資料都會保留，已取消的預約單不可再修改或再次取消。
     * @api-dict guest_apply_reservation_status 0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消
     * @api-no-request-body
     * @api-response-empty
     */
    public function cancel(int $id, Request $request, GuestApplyService $service)
    {
        $service->cancel($id);

        return $this->success();
    }

    /**
     * @api-method PUT
     * @api-path /api/guest/applies/{id}/status
     * @api-tag GuestApply
     * @api-summary 審核訪客狀態
     * @api-portal admin
     * @api-description
     * 傳入參數為1、20、30、40、50、60、70，審核過為1，再依據實際狀態為改為10或11，如果傳入值為60、70，清除 QR code 與速通碼、門禁卡號使其立即失效。
     * @api-request-field reservation_status int 預約單狀態
     * @api-request-example {"reservation_status": 1}
     * @api-response-empty
     */
    public function statusChange(int $id, Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'reservation_status' => 'int|in:1,20,30,40,50,60,70',
        ]);

        $service->status($id, $data['reservation_status']);

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/guest/applies/{id}
     * @api-tag GuestApply
     * @api-summary 刪除訪客預約單
     * @api-portal admin
     * @api-description
     * 刪除訪客預約單
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroy(string $id, GuestApplyService $service)
    {
        $service->delete($id);

        return $this->success();
    }

    private function rules(): array
    {
        return [
            'doorplate_id' => ['required', 'int', 'exists:site_current.doorplates,id'],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'visit_start_time' => ['required', 'date_format:H:i'],
            'visit_end_time' => ['required', 'date_format:H:i', 'after:visit_start_time'],
            'guest_count' => ['required', 'int', 'min:1', 'max:50'],
            'host_name' => ['required', 'string', 'max:100'],
            'visit_reason' => ['required', 'int', 'max:50'],
            'remark' => ['nullable', 'string', 'max:200'],
            'is_email_notify' => ['nullable', 'boolean'],
            'is_sms_notify' => ['nullable', 'boolean'],
            'is_auto_remind' => ['nullable', 'boolean'],
            'remind_time' => ['required_if:is_auto_remind,true,1', 'nullable', 'int', 'min:1', 'max:10080'],
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

    private function presentApplyId(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'reservation_type' => $apply->reservation_type,
            'reservation_status' => $apply->reservation_status,
            'host_company_name' => $apply->doorplate?->company_name,
            'host_floor' => $apply->doorplate?->floor,
            'host_name' => $apply->host_name,
            'visit_date' => $apply->visit_date?->format('Y-m-d'),
            'visit_start_time' => $this->hhmm($apply->visit_start_time),
            'visit_end_time' => $this->hhmm($apply->visit_end_time),
            'guest_count' => $apply->guest_count,
            'visit_reason' => $apply->visit_reason,
            'remark' => $apply->remark,
            'is_email_notify' => $apply->is_email_notify,
            'is_sms_notify' => $apply->is_sms_notify,
            'is_auto_remind' => $apply->is_auto_remind,
            'remind_time' => $apply->remind_time,
            'fast_pass_code' => $apply->fast_pass_code,
            'access_card_no' => $apply->access_card_no,
            'check_in_at' => $apply->check_in_at?->toDateTimeString(),
            'check_out_at' => $apply->check_out_at?->toDateTimeString(),
            'visitor' => $apply->guests->map(fn (Guest $g) => [
                'id' => $g->id,
                'guest_name' => $g->name,
                'guest_company_name' => $g->company_name,
                'guest_job_title' => $g->title,
                'guest_phone' => $g->phone,
                'guest_email' => $g->email,
                'guest_plate_number' => $g->plate_number,
            ])->all(),
            'created_at' => $apply->created_at?->toDateTimeString(),
            'updated_at' => $apply->updated_at?->toDateTimeString(),
        ];
    }

    /** 列表只需要摘要欄位 */
    private function presentListItem(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'reservation_type' => $apply->reservation_type,
            'reservation_status' => $apply->reservation_status,
            'host_name' => $apply->host_name,
            'host_company_name' => $apply->doorplate?->company_name,
            'host_floor' => $apply->doorplate?->floor,
            'visit_date' => $apply->visit_date?->format('Y-m-d'),
            'visit_start_time' => $this->hhmm($apply->visit_start_time),
            'visit_end_time' => $this->hhmm($apply->visit_end_time),
            'guest_count' => $apply->guest_count,
            'visit_reason' => $apply->visit_reason,
            'check_in_at' => $apply->check_in_at?->toDateTimeString(),
            'check_out_at' => $apply->check_out_at?->toDateTimeString(),
            'created_at' => $apply->created_at,
            'visitor' => $apply->guests->map(fn (Guest $g) => [
                'id' => $g->id,
                'guest_name' => $g->name,
                'guest_company_name' => $g->company_name,
                'guest_phone' => $g->phone,
            ])->all(),
        ];
    }

    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

}
