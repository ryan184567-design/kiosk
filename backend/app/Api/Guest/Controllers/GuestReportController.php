<?php

/* *****************************************
File: GuestReportController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客統計報表控制器：統計總覽（卡片、趨勢、事由與樓層分布）與每日統計明細（JSON／Excel／PDF）。
資料來源是每日彙整的緩存表 guest_daily_stats，不掃預約單。
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Services\GuestStatService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Export\TableExporter;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class GuestReportController extends BaseApiController
{
    use PaginationHelper;

    /** 明細輸出類型 */
    private const EXPORT_JSON = 0;
    private const EXPORT_EXCEL = 1;
    private const EXPORT_PDF = 2;

    /** 匯出 Excel／PDF 單次最多筆數（一天一列，約 5 年） */
    private const EXPORT_LIMIT = 1830;

    private const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

    /**
     * @api-method GET
     * @api-path /api/guest/reports
     * @api-tag GuestReport
     * @api-summary 訪客統計總覽
     * @api-description
     * 統計報表頁上半部：五張卡片、每日來訪人次趨勢、來訪事由分布、樓層來訪分布（Top 5）。資料來自每日彙整的緩存表。
     * - 資料每天凌晨 00:05 彙整前一日，所以**當天的資料要隔天才會出現**，統計期間建議截止到昨天
     * - 統計範圍為已報到（已到、已離場）的預約單，人次以預約單的 `guest_count` 加總，日期以預約日期為準
     * - `date_from`、`date_to` 必填，區間最多 731 天（含頭尾）；各卡片的 `growth_rate` 是與「緊接在前、相同天數」的期間比較，前一期為 0 時為 null
     * - `floor`、`doorplate_id`、`visit_reason` 為篩選條件，三者可任意組合，留空代表不篩選；
     *   各下拉選項來源：樓層與公司（門牌）見資料設定模塊，來訪事由見字典 `guest_visit_reason`
     * - 來訪公司數＝期間內不重複的受訪公司數；發證數＝臨時卡發放數，目前每位來訪者配發一張
     * - `group_by` 控制趨勢圖分組：`day`（預設，每日一點）或 `month`（每月一點，查一年時用，date 格式為 YYYY-MM）；沒有資料的日期／月份會補 0
     * @api-portal admin
     * @api-dict guest_visit_reason 1=業務洽談,2=工程維護,3=會議,4=送貨/收發,5=面試,6=參觀訪問,7=設備檢測,99=其他
     * @api-dict guest_report_group_by day=按日,month=按月
     * @api-query-field date_from string 統計期間起 YYYY-MM-DD，必填
     * @api-query-field date_to string 統計期間迄 YYYY-MM-DD，必填，不可早於 date_from，與 date_from 相隔最多 731 天
     * @api-query-field floor int 樓層，選填；正數為地上樓層（5=5F），負數為地下（-1=B1），不傳為全部
     * @api-query-field doorplate_id int 受訪公司（門牌）ID，選填，不傳為全部公司
     * @api-query-field visit_reason int 來訪事由，選填，字典 guest_visit_reason 的值，不傳為全部
     * @api-query-field group_by string 趨勢分組，選填，預設 day；day=按日、month=按月
     * @api-no-request-body
     * @api-response-field data.cards.total.count int 來訪總人次
     * @api-response-field data.cards.total.growth_rate float 較上一期成長百分比（12.8 代表 +12.8%，負數為下降），上一期為 0 時為 null
     * @api-response-field data.cards.reserved.count int 預約來訪人次
     * @api-response-field data.cards.reserved.percent float 占來訪總人次的百分比
     * @api-response-field data.cards.reserved.growth_rate float 較上一期成長百分比，上一期為 0 時為 null
     * @api-response-field data.cards.onsite.count int 臨時（現場）來訪人次
     * @api-response-field data.cards.onsite.percent float 占來訪總人次的百分比
     * @api-response-field data.cards.onsite.growth_rate float 較上一期成長百分比，上一期為 0 時為 null
     * @api-response-field data.cards.companies.count int 來訪公司數（不重複的受訪公司）
     * @api-response-field data.cards.companies.growth_rate float 較上一期成長百分比，上一期為 0 時為 null
     * @api-response-field data.cards.cards.count int 發證數（臨時卡）
     * @api-response-field data.cards.cards.growth_rate float 較上一期成長百分比，上一期為 0 時為 null
     * @api-response-field data.trend array 趨勢，依日期由舊到新
     * @api-response-field data.trend[].date string 日期 YYYY-MM-DD；group_by=month 時為 YYYY-MM
     * @api-response-field data.trend[].count int 來訪人次
     * @api-response-field data.reasons array 來訪事由分布，依人次由多到少
     * @api-response-field data.reasons[].visit_reason dict:guest_visit_reason 來訪事由
     * @api-response-field data.reasons[].label string 事由顯示文字，字典沒有對應項目時為「其他」
     * @api-response-field data.reasons[].count int 來訪人次
     * @api-response-field data.reasons[].percent float 占來訪總人次的百分比
     * @api-response-field data.floors array 樓層來訪分布，人次最多的前 5 個樓層，依人次由多到少
     * @api-response-field data.floors[].floor int 樓層，負數為地下
     * @api-response-field data.floors[].floor_label string 樓層顯示文字，例如 5F、B1
     * @api-response-field data.floors[].count int 來訪人次
     * @api-response-field data.floors[].percent float 占來訪總人次的百分比
     */
    public function reports(Request $request, GuestStatService $service)
    {
        $data = $this->validateInput($request, [
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'floor' => 'nullable|integer|between:-99,999',
            'doorplate_id' => 'nullable|integer|min:1',
            'visit_reason' => 'nullable|integer|min:0',
            'group_by' => 'nullable|in:day,month',
        ]);

        [$from, $to] = $this->range($data);

        return $this->success($service->overview(
            $from,
            $to,
            $this->nullableInt($data, 'floor'),
            $this->nullableInt($data, 'doorplate_id'),
            $this->nullableInt($data, 'visit_reason'),
            $data['group_by'] ?? 'day',
        ));
    }

    /**
     * @api-method GET
     * @api-path /api/guest/reports/details
     * @api-tag GuestReport
     * @api-summary 訪客統計明細
     * @api-description
     * 統計報表頁下方「訪客統計明細」：每天一列，依日期由新到舊。資料來自每日彙整的緩存表，篩選條件與口徑和統計總覽（`GET /api/guest/reports`）一致。
     * - `export` 決定輸出類型：0（預設）回傳 JSON 列表；1 下載 Excel（.xlsx）；2 下載 PDF
     * - `export` 為 1 或 2 時直接回傳檔案（不是 JSON），忽略 `page`、`per_page`，匯出符合條件的全部日期，最多 1830 筆
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * - 當天的資料要隔天凌晨 00:05 彙整後才會出現；沒有任何來訪的日期不會有這一列
     * @api-portal admin
     * @api-dict guest_visit_reason 1=業務洽談,2=工程維護,3=會議,4=送貨/收發,5=面試,6=參觀訪問,7=設備檢測,99=其他
     * @api-dict guest_report_export 0=JSON列表,1=Excel,2=PDF
     * @api-query-field export int 輸出類型，選填，預設 0；0=JSON列表、1=Excel、2=PDF
     * @api-query-field date_from string 統計期間起 YYYY-MM-DD，必填
     * @api-query-field date_to string 統計期間迄 YYYY-MM-DD，必填，不可早於 date_from，與 date_from 相隔最多 731 天
     * @api-query-field floor int 樓層，選填；正數為地上樓層（5=5F），負數為地下（-1=B1），不傳為全部
     * @api-query-field doorplate_id int 受訪公司（門牌）ID，選填，不傳為全部公司
     * @api-query-field visit_reason int 來訪事由，選填，字典 guest_visit_reason 的值，不傳為全部
     * @api-query-field page int 頁碼，選填，預設 1，export 為 0 時有效
     * @api-query-field per_page int 每頁筆數，選填，預設 10，-1 代表不分頁，最大 200，export 為 0 時有效
     * @api-no-request-body
     * @api-response-field data.items[].date string 日期 YYYY-MM-DD
     * @api-response-field data.items[].weekday string 星期，日、一、二、三、四、五、六
     * @api-response-field data.items[].total int 來訪總人次
     * @api-response-field data.items[].reserved int 預約人次
     * @api-response-field data.items[].onsite int 臨時人次
     * @api-response-field data.items[].companies int 來訪公司數（當天不重複的受訪公司）
     * @api-response-field data.items[].top_visit_reason dict:guest_visit_reason 最主要來訪事由（當天人次最多的事由）
     * @api-response-field data.items[].top_visit_reason_label string 最主要來訪事由的顯示文字
     * @api-response-field data.total int 符合條件的總筆數（天數）
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function reportDetails(Request $request, GuestStatService $service, TableExporter $exporter)
    {
        $data = $this->validateInput($request, [
            'export' => 'nullable|integer|in:0,1,2',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'floor' => 'nullable|integer|between:-99,999',
            'doorplate_id' => 'nullable|integer|min:1',
            'visit_reason' => 'nullable|integer|min:0',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:-1|max:200',
        ]);

        [$from, $to] = $this->range($data);
        $floor = $this->nullableInt($data, 'floor');
        $doorplateId = $this->nullableInt($data, 'doorplate_id');
        $reason = $this->nullableInt($data, 'visit_reason');

        $query = $service->details($from, $to, $floor, $doorplateId, $reason);
        $export = (int) ($data['export'] ?? self::EXPORT_JSON);

        if ($export !== self::EXPORT_JSON) {
            $rows = $query->limit(self::EXPORT_LIMIT)->get();
            $items = $this->presentItems($rows->all(), $service, $floor, $doorplateId, $reason);

            return $this->export($export, $items, $exporter);
        }

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet($query, $perPage === 0 ? 10 : $perPage, (int) ($data['page'] ?? 1));
        $result['items'] = $this->presentItems($result['items']->all(), $service, $floor, $doorplateId, $reason);

        return $this->success($result);
    }

    /** 明細轉成輸出格式，「最主要事由」對這批日期一次查完 */
    private function presentItems(array $rows, GuestStatService $service, ?int $floor, ?int $doorplateId, ?int $reason): array
    {
        $dates = array_map(fn ($r) => $r->stat_date->toDateString(), $rows);
        $top = $service->topReasons($dates, $floor, $doorplateId, $reason);

        return array_map(function ($r) use ($top) {
            $day = $r->stat_date->toDateString();

            return [
                'date' => $day,
                'weekday' => self::WEEKDAYS[$r->stat_date->dayOfWeek],
                'total' => (int) $r->total,
                'reserved' => (int) $r->reserved,
                'onsite' => (int) $r->onsite,
                'companies' => (int) $r->companies,
                'top_visit_reason' => $top[$day]['value'] ?? null,
                'top_visit_reason_label' => $top[$day]['label'] ?? null,
            ];
        }, $rows);
    }

    /** 明細匯出：1 Excel、2 PDF，欄位與畫面上的明細一致 */
    private function export(int $export, array $items, TableExporter $exporter)
    {
        $title = __('api.guest_report.export_title');
        $headers = array_values(__('api.guest_report.export_headers'));

        $rows = array_map(fn (array $i) => [
            $i['date'].' ('.$i['weekday'].')',
            $i['total'],
            $i['reserved'],
            $i['onsite'],
            $i['companies'],
            $i['top_visit_reason_label'],
        ], $items);

        $filename = 'guest_report_'.now()->format('YmdHis').($export === self::EXPORT_EXCEL ? '.xlsx' : '.pdf');

        return $export === self::EXPORT_EXCEL
            ? $exporter->xlsx($title, $headers, $rows, $filename)
            : $exporter->pdf($title, $headers, $rows, $filename);
    }

    /** 解析統計期間並檢查天數上限 */
    private function range(array $data): array
    {
        $from = Carbon::createFromFormat('!Y-m-d', $data['date_from']);
        $to = Carbon::createFromFormat('!Y-m-d', $data['date_to']);

        if ($from->diffInDays($to) + 1 > GuestStatService::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => __('api.guest_report.range_too_long', ['days' => GuestStatService::MAX_RANGE_DAYS]),
            ]);
        }

        return [$from, $to];
    }

    private function nullableInt(array $data, string $key): ?int
    {
        return isset($data[$key]) ? (int) $data[$key] : null;
    }
}
