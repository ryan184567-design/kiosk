<?php

/* *****************************************
File: GuestRecordController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客記錄控制器：儀表板統計、記錄列表（JSON／Excel／PDF）、記錄詳情
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Services\GuestReasonDictionary;
use App\Api\Guest\Services\GuestRecordService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Export\TableExporter;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

class GuestRecordController extends BaseApiController
{
    use PaginationHelper;

    /** 列表輸出類型 */
    private const EXPORT_JSON = 0;
    private const EXPORT_EXCEL = 1;
    private const EXPORT_PDF = 2;

    /**
     * @api-method GET
     * @api-path /api/guest/records/stats
     * @api-tag GuestRecord
     * @api-summary 訪客記錄儀表板
     * @api-description
     * 訪客記錄頁最上方四張統計卡片。只統計已報到（已到、已離場）的預約單，人數以預約單的 `guest_count` 加總，日期以預約日期為準。
     * - 今日：與昨日比較；本週：週一至週日，與上週比較；本月：與上個月比較
     * - `growth_rate` 為成長百分比（例如 20 代表 +20%，負數代表下降），上一期為 0 時為 null
     * - 完成訪客為累計已離場（reservation_status=50）的人數，沒有成長率
     * @api-portal admin
     * @api-no-request-body
     * @api-response-field data.today.count int 今日訪客人數
     * @api-response-field data.today.previous_count int 昨日訪客人數
     * @api-response-field data.today.growth_rate float 較昨日成長百分比，昨日為 0 時為 null
     * @api-response-field data.week.count int 本週訪客人數
     * @api-response-field data.week.previous_count int 上週訪客人數
     * @api-response-field data.week.growth_rate float 較上週成長百分比，上週為 0 時為 null
     * @api-response-field data.month.count int 本月訪客人數
     * @api-response-field data.month.previous_count int 上月訪客人數
     * @api-response-field data.month.growth_rate float 較上月成長百分比，上月為 0 時為 null
     * @api-response-field data.completed.count int 累計完成（已離場）訪客人數
     */
    public function recordStats(GuestRecordService $service)
    {
        return $this->success($service->stats());
    }

    /**
     * @api-method GET
     * @api-path /api/guest/records
     * @api-tag GuestRecord
     * @api-summary 訪客記錄列表
     * @api-description
     * 查詢已報到（已到、已離場）的訪客記錄，依預約日期與起始時間由新到舊排序。
     * - `export` 決定輸出類型：0（預設）回傳 JSON 列表；1 下載 Excel（.xlsx）；2 下載 PDF
     * - `export` 為 1 或 2 時直接回傳檔案（不是 JSON），忽略 `page`、`per_page`，匯出符合篩選條件的全部資料，最多 5000 筆
     * - `date_from`／`date_to` 為預約日期區間（含頭尾），兩者皆可單獨使用
     * - `guest_name`、`company_name` 比對來訪者姓名、公司名稱；`visit_reason` 為來訪事由（字典 guest_visit_reason 的值，精確比對）；`keyword` 同時比對單號、受訪人、來訪者姓名、公司、手機、車牌（皆為模糊查詢）
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * @api-portal admin
     * @api-dict guest_record_status 40=在場中,50=已離場
     * @api-dict guest_visit_reason 1=業務洽談,2=工程維護,3=會議,4=送貨/收發,5=面試,6=參觀訪問,7=設備檢測,99=其他
     * @api-dict guest_record_export 0=JSON列表,1=Excel,2=PDF
     * @api-query-field export int 輸出類型，選填，預設 0；0=JSON列表、1=Excel、2=PDF
     * @api-query-field date_from string 預約日期起 YYYY-MM-DD，選填
     * @api-query-field date_to string 預約日期迄 YYYY-MM-DD，選填，不可早於 date_from
     * @api-query-field guest_name string 來訪者姓名，選填，最長 100 字
     * @api-query-field company_name string 來訪者公司名稱，選填，最長 150 字
     * @api-query-field visit_reason int 來訪事由，選填，字典 guest_visit_reason 的值，不傳為全部
     * @api-query-field reservation_status int 狀態，選填；40=在場中、50=已離場，不傳為全部
     * @api-query-field keyword string 關鍵字，選填，最長 100 字
     * @api-query-field page int 頁碼，選填，預設 1，export 為 0 時有效
     * @api-query-field per_page int 每頁筆數，選填，預設 10，-1 代表不分頁，最大 200，export 為 0 時有效
     * @api-no-request-body
     * @api-response-field data.items[].id int 預約單ID（查詳情用）
     * @api-response-field data.items[].reservation_id string 預約單號
     * @api-response-field data.items[].reservation_status dict:guest_record_status 狀態
     * @api-response-field data.items[].visit_at string 來訪時間（預約日期加起始時間）YYYY-MM-DD HH:mm
     * @api-response-field data.items[].guest_name string 來訪者姓名，有多位時為第一位
     * @api-response-field data.items[].guest_company_name string 來訪者公司名稱，有多位時為第一位，未填為 null
     * @api-response-field data.items[].guest_count int 訪客人數
     * @api-response-field data.items[].visit_reason dict:guest_visit_reason 來訪事由
     * @api-response-field data.items[].host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.items[].host_floor string 接待樓層，門牌不存在時為 null
     * @api-response-field data.items[].host_name string 受訪人
     * @api-response-field data.items[].check_in_at datetime 進場時間
     * @api-response-field data.items[].check_out_at datetime 離場時間，在場中為 null
     * @api-response-field data.items[].visitor array 來訪者明細
     * @api-response-field data.items[].visitor[].id int 來訪者ID
     * @api-response-field data.items[].visitor[].guest_name string 來訪者姓名
     * @api-response-field data.items[].visitor[].guest_company_name string 公司名稱
     * @api-response-field data.items[].visitor[].guest_phone string 手機號碼
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function records(Request $request, GuestRecordService $service, TableExporter $exporter, GuestReasonDictionary $reasons)
    {
        $data = $this->validateInput($request, [
            'export' => 'nullable|integer|in:0,1,2',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'guest_name' => 'nullable|string|max:100',
            'company_name' => 'nullable|string|max:150',
            'visit_reason' => 'nullable|integer|min:0',
            'reservation_status' => 'nullable|integer|in:40,50',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        $query = $service->records(
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
            $data['guest_name'] ?? null,
            $data['company_name'] ?? null,
            isset($data['visit_reason']) ? (int) $data['visit_reason'] : null,
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

        $result['items'] = collect($result['items'])->map(fn (GuestApply $a) => $this->presentListItem($a))->all();

        return $this->success($result);
    }

    /**
     * @api-method GET
     * @api-path /api/guest/records/{id}
     * @api-tag GuestRecord
     * @api-summary 訪客記錄詳情
     * @api-description
     * 取得單筆訪客記錄（右側「訪客詳細資料」面板）。只有已報到（已到、已離場）的預約單查得到，其餘回 404。
     * `health_declaration` 為 true 代表訪客在報到時已同意健康聲明（同意書）。
     * @api-portal admin
     * @api-dict guest_record_status 40=在場中,50=已離場
     * @api-dict guest_visit_reason 1=業務洽談,2=工程維護,3=會議,4=送貨/收發,5=面試,6=參觀訪問,7=設備檢測,99=其他
     * @api-no-request-body
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.reservation_status dict:guest_record_status 狀態
     * @api-response-field data.visit_at string 來訪時間（預約日期加起始時間）YYYY-MM-DD HH:mm
     * @api-response-field data.host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.host_floor string 接待樓層，門牌不存在時為 null
     * @api-response-field data.host_name string 受訪人
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.visit_reason dict:guest_visit_reason 來訪事由
     * @api-response-field data.remark string 備註，未填為 null
     * @api-response-field data.access_card_no string 門禁卡號，尚未配發為 null
     * @api-response-field data.health_declaration bool 健康聲明是否已同意
     * @api-response-field data.created_at datetime 登記時間
     * @api-response-field data.check_in_at datetime 進場時間
     * @api-response-field data.check_out_at datetime 離場時間，在場中為 null
     * @api-response-field data.visitor array 來訪者明細
     * @api-response-field data.visitor[].id int 來訪者ID
     * @api-response-field data.visitor[].guest_name string 來訪者姓名
     * @api-response-field data.visitor[].guest_company_name string 公司名稱，未填為 null
     * @api-response-field data.visitor[].guest_job_title string 職稱，未填為 null
     * @api-response-field data.visitor[].guest_phone string 手機號碼
     * @api-response-field data.visitor[].guest_email string Email，未填為 null
     * @api-response-field data.visitor[].guest_plate_number string 車牌號碼，未填為 null
     */
    public function recordShow(int $id, GuestRecordService $service)
    {
        return $this->success($this->presentDetail($service->show($id)));
    }

    /** 列表匯出：1 Excel、2 PDF，欄位與畫面上的清單一致 */
    private function export(int $export, array $applies, TableExporter $exporter, GuestReasonDictionary $reasons)
    {
        $title = __('api.guest_record.export_title');
        $headers = array_values(__('api.guest_record.export_headers'));

        $rows = array_map(function (GuestApply $a) use ($reasons) {
            $first = $a->guests->first();

            return [
                $a->reservation_id,
                $this->visitAt($a),
                $first?->name,
                $first?->company_name,
                $reasons->label($a->visit_reason),
                $a->doorplate?->company_name,
                $a->doorplate?->floor,
                $a->host_name,
                $a->check_in_at?->format('Y-m-d H:i'),
                $a->check_out_at?->format('Y-m-d H:i'),
                $this->statusLabel($a->reservation_status),
            ];
        }, $applies);

        $filename = 'guest_records_'.now()->format('YmdHis').($export === self::EXPORT_EXCEL ? '.xlsx' : '.pdf');

        return $export === self::EXPORT_EXCEL
            ? $exporter->xlsx($title, $headers, $rows, $filename)
            : $exporter->pdf($title, $headers, $rows, $filename);
    }

    private function statusLabel(int $status): string
    {
        return __('api.guest_record.status.'.$status);
    }

    /** MySQL time 欄位讀出來是 "HH:mm:ss"，截成 "HH:mm" */
    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    private function visitAt(GuestApply $apply): string
    {
        return trim($apply->visit_date?->format('Y-m-d').' '.$this->hhmm($apply->visit_start_time));
    }

    private function presentListItem(GuestApply $apply): array
    {
        $first = $apply->guests->first();

        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'reservation_status' => $apply->reservation_status,
            'visit_at' => $this->visitAt($apply),
            'guest_name' => $first?->name,
            'guest_company_name' => $first?->company_name,
            'guest_count' => $apply->guest_count,
            'visit_reason' => $apply->visit_reason,
            'host_company_name' => $apply->doorplate?->company_name,
            'host_floor' => $apply->doorplate?->floor,
            'host_name' => $apply->host_name,
            'check_in_at' => $apply->check_in_at?->toDateTimeString(),
            'check_out_at' => $apply->check_out_at?->toDateTimeString(),
            'visitor' => $apply->guests->map(fn (Guest $g) => [
                'id' => $g->id,
                'guest_name' => $g->name,
                'guest_company_name' => $g->company_name,
                'guest_phone' => $g->phone,
            ])->all(),
        ];
    }

    private function presentDetail(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'reservation_status' => $apply->reservation_status,
            'visit_at' => $this->visitAt($apply),
            'host_company_name' => $apply->doorplate?->company_name,
            'host_floor' => $apply->doorplate?->floor,
            'host_name' => $apply->host_name,
            'guest_count' => $apply->guest_count,
            'visit_reason' => $apply->visit_reason,
            'remark' => $apply->remark,
            'access_card_no' => $apply->access_card_no,
            'health_declaration' => (bool) $apply->consent_form,
            'created_at' => $apply->created_at?->toDateTimeString(),
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
        ];
    }
}
