<?php

namespace App\Api\Guest\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Export\TableExporter;
use Illuminate\Http\Request;

use App\Api\Guest\Models\GuestApply;

use App\Api\Guest\Services\GuestRecordService;
use App\Api\Guest\Services\GuestApprovalService;
use App\Api\Guest\Services\GuestReasonDictionary;

class GuestApprovalController extends BaseApiController
{
    use PaginationHelper;

    /** 列表輸出類型 */
    private const EXPORT_JSON = 0;
    private const EXPORT_EXCEL = 1;

    /**
     * @api-method GET
     * @api-path /api/guest/approvals
     * @api-tag GuestApproval
     * @api-summary 訪客審核列表
     * @api-description
     * 查詢待審核、已核准、已拒絕的訪客(預約單)列表，依預約單建立日期由新到舊排序。
     * **公司端口（company）**：只會查到登入者所屬公司（門牌）的資料，admin 端口不受限。
     * - `export` 決定輸出類型：0（預設）回傳 JSON 列表；1 下載 Excel（.xlsx）；2 下載 PDF
     * - `export` 為 1 或 2 時直接回傳檔案（不是 JSON），忽略 `page`、`per_page`，匯出符合篩選條件的全部資料，最多 5000 筆
     * - `date_from`／`date_to` 為預約日期區間（含頭尾），兩者皆可單獨使用
     * - `keyword` 同時比對單號、來訪者姓名、公司（皆為模糊查詢）
     * - `reservation_status` 審核狀態，今日訪客、本週訪客列表不回傳審核狀態
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * @api-portal admin,company
     * @api-dict guest_record_status 0=待審核,1=修改待審核,10=已核准,11=修改已核准,20=已通知,30=未到,40=已到,50=已離場,60=已取消,70=已拒絕
     * @api-dict guest_record_export 0=JSON列表,1=Excel,2=PDF
     * @api-query-field export int 輸出類型，選填，預設 0；0=JSON列表、1=Excel、2=PDF
     * @api-query-field date_from string 申請日期起 YYYY-MM-DD，選填
     * @api-query-field date_to string 申請日期迄 YYYY-MM-DD，選填，不可早於 date_from
     * @api-query-field apply_date_from string 預約日期起 YYYY-MM-DD，選填
     * @api-query-field apply_date_to string 預約日期迄 YYYY-MM-DD，選填，不可早於 apply_date_from
     * @api-query-field reservation_status int 狀態，選填；0=待審核、1=已審核、70=已拒絕，不傳為全部
     * @api-query-field keyword string 關鍵字，選填，最長 100 字
     * @api-query-field page int 頁碼，選填，預設 1，export 為 0 時有效
     * @api-query-field per_page int 每頁筆數，選填，預設 10，-1 代表不分頁，最大 200，export 為 0 時有效
     * @api-no-request-body
     * @api-response-field data.items[].id int 預約單ID（查詳情用）
     * @api-response-field data.items[].reservation_id string 預約單號
     * @api-response-field data.items[].reservation_status dict:guest_record_status 狀態
     * @api-response-field data.items[].visit_at string 來訪時間（預約日期加起始時間）MM/DD HH:mm-HH:mm
     * @api-response-field data.items[].guest_name string 來訪者姓名，有多位時為第一位
     * @api-response-field data.items[].guest_company_name string 來訪者公司名稱，有多位時為第一位，未填為 null
     * @api-response-field data.items[].guest_title string 來訪者職稱，有多位時為第一位，未填為 null
     * @api-response-field data.items[].guest_phone string 來訪者手機號碼，有多位時為第一位，未填為 null
     * @api-response-field data.items[].guest_email string 來訪者Email，有多位時為第一位，未填為 null
     * @api-response-field data.items[].remark string 備註
     * @api-response-field data.items[].guest_count int 訪客人數
     * @api-response-field data.items[].visit_reason string 來訪事由，未填為 null
     * @api-response-field data.items[].host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.items[].host_floor string 接待樓層，門牌不存在時為 null
     * @api-response-field data.items[].host_name string 受訪人
     * @api-response-field data.reason object 來訪事由列表
     * @api-response-field data.reason[] string 來訪事由選項。key 為選項代碼（數字，以字串形式出現），value 為顯示文字；
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function approval(Request $request, GuestApprovalService $service, TableExporter $exporter, GuestReasonDictionary $reasons)
    {
        $data = $this->validateInput($request, [
            'export' => 'nullable|integer|in:0,1,2',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'apply_date_from' => 'nullable|date_format:Y-m-d',
            'apply_date_to' => 'nullable|date_format:Y-m-d|after_or_equal:apply_date_from',
            'reservation_status' => 'nullable|integer|in:0,1,70',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        $query = $service->list(
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
            $data['apply_date_from'] ?? null,
            $data['apply_date_to'] ?? null,
            isset($data['reservation_status']) ? (int) $data['reservation_status'] : null,
            $data['keyword'] ?? null,
        );

        $export = (int) ($data['export'] ?? self::EXPORT_JSON);

        if ($export !== self::EXPORT_JSON) {
            return $this->export($export, $query->limit(GuestRecordService::EXPORT_LIMIT)->get()->all(), $exporter, $reasons);
        }

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $query,
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestApply $a) => $this->present($a))->all();
        $result['reason'] = $reasons->labels();

        return $this->success($result);

    }

    /**
     * @api-method GET
     * @api-path /api/guest/approvals/data
     * @api-tag GuestApproval
     * @api-summary 訪客審核人數
     * @api-description
     * 待審核包含待審核(0)與修改待審核(1)，已核准包含已核准(10)與修改已核准(11)
     * **公司端口（company）**：只會查到登入者所屬公司（門牌）的資料，admin 端口不受限。
     * 訪客人數以`guest_count`人次加總，訪客人數不包含狀態`reservation_status`為未到(30)、已取消(60)、已拒絕(70)
     * @api-portal admin,company
     * @api-no-request-body
     * @api-response-field data.status[].0 int 待審核
     * @api-response-field data.status[].1 int 已核准
     * @api-response-field data.status[].70 int 已拒絕
     * @api-response-field data.today int 今日訪客人數
     * @api-response-field data.weekly int 本週訪客人數
     */
    public function approvalStatus(GuestApprovalService $service)
    {
        // 審核狀態數量
        $approvalArr = $service->status();
        // 本週訪客數量
        $weekly = $service->weeklyGuest();
        // 今日訪客數量
        $today = $service->todayGuest();

        $result['status'] = $approvalArr;
        $result['today'] = $today;
        $result['weekly'] = $weekly;

        return $result;
    }

    /** 列表匯出：1 Excel、2 PDF，欄位與畫面上的清單一致 */
    private function export(int $export, array $applies, TableExporter $exporter, GuestReasonDictionary $reasons)
    {
        $title = __('api.guest_approval.export_title');
        $headers = array_values(__('api.guest_approval.export_headers'));

        $rows = array_map(function (GuestApply $a) use ($reasons) {
            $first = $a->guests->first();

            return [
                $a->created_at,
                $a->reservation_id,
                $first?->name,
                $first?->company_name,
                $this->visitAt($a),
                $reasons->label($a->visit_reason),
                $this->statusLabel($a->reservation_status),
            ];
        }, $applies);

        $filename = 'guest_approvals_'.now()->format('YmdHis').($export === self::EXPORT_EXCEL ? '.xlsx' : '.pdf');

        return $export === self::EXPORT_EXCEL
            ? $exporter->xlsx($title, $headers, $rows, $filename)
            : $exporter->pdf($title, $headers, $rows, $filename);
    }

    private function statusLabel(int $status): string
    {
        return __('api.guest_approval.status.'.$status);
    }
    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    private function visitAt(GuestApply $apply): string
    {
        return trim($apply->visit_date?->format('m/d').' '.$this->hhmm($apply->visit_start_time).'-'.$this->hhmm($apply->visit_end_time));
    }

    private function visitDate(GuestApply $apply): string
    {
        $weekMap = [
            0 => '日',
            1 => '一',
            2 => '二',
            3 => '三',
            4 => '四',
            5 => '五',
            6 => '六',
        ];

        $weekday = $weekMap[(int) date('w', strtotime($apply->visit_date))];

        $date = date('Y/m/d', strtotime($apply->visit_date));

        return $date.' ('.$weekday.')';
    }
    private function present(GuestApply $apply): array
    {
        $first = $apply->guests->first();

        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'reservation_status' => $apply->reservation_status,
            'visit_at' => $this->visitAt($apply),
            'visit_date' => $this->visitDate($apply),
            'visit_start_time' => $apply->visit_start_time,
            'visit_end_time' => $apply->visit_end_time,
            'remark' => $apply->remark,
            'guest_count' => $apply->guest_count,
            'visit_reason' => $apply->visit_reason,
            'host_company_name' => $apply->doorplate?->company_name,
            'host_name' => $apply->host_name,
            'host_floor' => $apply->doorplate?->floor,

            'guest_name' => $first?->name,
            'guest_company_name' => $first?->company_name,
            'guest_title' => $first?->title,
            'guest_phone' => $first?->phone,
            'guest_email' => $first?->email,
        ];
    }
}
