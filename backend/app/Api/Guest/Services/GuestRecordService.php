<?php

/* *****************************************
File: GuestRecordService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客記錄 Service：儀表板統計、記錄列表查詢與詳情。
訪客記錄＝「已報到」的預約單（狀態 40 已到、50 已離場），所以沒報到的預約單不會出現在這裡。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\GuestApply;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

class GuestRecordService
{
    /** 匯出 Excel／PDF 單次最多筆數，避免一次撈出整張表把記憶體吃光 */
    public const EXPORT_LIMIT = 5000;

    /** 訪客記錄的狀態範圍：已到、已離場 */
    private const RECORD_STATUSES = [
        GuestApply::STATUS_CHECKED_IN,
        GuestApply::STATUS_CHECKED_OUT,
    ];

    /**
     * 儀表板：今日／本週／本月訪客人數（含較上一個同長度期間的成長率），以及累計完成訪客數。
     * 人數以預約單的 guest_count 加總，日期以預約日期（visit_date）為準，與列表的日期篩選同一基準。
     */
    public function stats(): array
    {
        $today = Carbon::today();

        $periods = [
            'today' => [$today, $today, $today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [
                $today->copy()->startOfWeek(), $today->copy()->endOfWeek(),
                $today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek(),
            ],
            'month' => [
                $today->copy()->startOfMonth(), $today->copy()->endOfMonth(),
                $today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
        ];

        $result = [];
        foreach ($periods as $key => [$from, $to, $prevFrom, $prevTo]) {
            $count = $this->headcount($from, $to);
            $prev = $this->headcount($prevFrom, $prevTo);

            $result[$key] = [
                'count' => $count,
                'previous_count' => $prev,
                // 上一期為 0 無法計算成長率，回 null 讓前端顯示「—」
                'growth_rate' => $prev > 0 ? round(($count - $prev) / $prev * 100, 1) : null,
            ];
        }

        $result['completed'] = [
            'count' => (int) $this->base()->where('reservation_status', GuestApply::STATUS_CHECKED_OUT)->sum('guest_count'),
        ];

        return $result;
    }

    /**
     * 訪客記錄查詢條件（不分頁，分頁由 Controller 的 paginateOrGet 處理；匯出直接 get）。
     */
    public function records(
        ?string $date_from = null,
        ?string $date_to = null,
        ?string $guest_name = null,
        ?string $company_name = null,
        ?string $visit_reason = null,
        ?int $status = null,
        ?string $keyword = null,
    ): Builder {
        $query = $this->base()
            ->with(['guests', 'doorplate:id,company_name,floor'])
            ->orderByDesc('visit_date')->orderByDesc('visit_start_time')->orderByDesc('id');

        if (! empty($date_from)) {
            $query->whereDate('visit_date', '>=', $date_from);
        }
        if (! empty($date_to)) {
            $query->whereDate('visit_date', '<=', $date_to);
        }
        if (! empty($guest_name)) {
            $query->whereHas('guests', fn ($g) => $g->where('name', 'like', '%'.$this->escapeLike($guest_name).'%'));
        }
        if (! empty($company_name)) {
            $query->whereHas('guests', fn ($g) => $g->where('company_name', 'like', '%'.$this->escapeLike($company_name).'%'));
        }
        if (! empty($visit_reason)) {
            $query->where('visit_reason', 'like', '%'.$this->escapeLike($visit_reason).'%');
        }
        if ($status !== null) {
            $query->where('reservation_status', $status);
        }
        if (! empty($keyword)) {
            $kw = '%'.$this->escapeLike($keyword).'%';
            $query->where(function ($q) use ($kw) {
                $q->where('reservation_id', 'like', $kw)
                    ->orWhere('host_name', 'like', $kw)
                    ->orWhereHas('guests', fn ($g) => $g->where('name', 'like', $kw)
                        ->orWhere('company_name', 'like', $kw)
                        ->orWhere('phone', 'like', $kw)
                        ->orWhere('plate_number', 'like', $kw));
            });
        }

        return $query;
    }

    /**
     * 訪客記錄詳情。只找得到已報到的預約單，沒報到的單號一律當作不存在。
     */
    public function show(int $id): GuestApply
    {
        $apply = $this->base()
            ->with(['guests', 'doorplate:id,company_name,floor'])
            ->find($id);

        if (! $apply) {
            throw new RuntimeException(__('api.guest_record.not_found'), 404);
        }

        return $apply;
    }

    private function base(): Builder
    {
        return GuestApply::query()->whereIn('reservation_status', self::RECORD_STATUSES);
    }

    private function headcount(Carbon $from, Carbon $to): int
    {
        return (int) $this->base()
            ->whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])
            ->sum('guest_count');
    }

    /** 使用者輸入的 % _ 不當成萬用字元，否則輸入 % 會撈出全部資料 */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
