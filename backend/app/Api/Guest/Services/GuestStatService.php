<?php

/* *****************************************
File: GuestStatService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客統計 Service：
1. aggregate() 把某一天已報到的預約單彙整進緩存表 guest_daily_stats（每日凌晨由 guest:stats-aggregate 呼叫，可重跑）
2. overview() / details() 統計報表頁的兩支 API 都只查緩存表，不掃 guest_applies
統計範圍＝已報到（已到、已離場）的預約單，日期以預約日期 visit_date 為準，與訪客記錄同一基準。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Models\GuestDailyStat;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GuestStatService
{
    /** 統計範圍內最多可查幾天（含頭尾），避免一次查出過長區間 */
    public const MAX_RANGE_DAYS = 731;

    /** 樓層分布取前幾名 */
    public const TOP_FLOORS = 5;

    public function __construct(private readonly GuestReasonDictionary $reasons) {}

    /**
     * 彙整指定日期並寫入緩存表，回傳寫入列數。
     * 先刪後寫、包在交易內，同一天重跑結果一致（補資料、修正來源資料後重算都安全）。
     */
    public function aggregate(Carbon $date): int
    {
        $day = $date->toDateString();

        // 門牌用 left join：門牌被刪除或不存在時，樓層／公司記為 0，數字不會憑空消失。
        // 已刪除的預約單由 GuestApply 的軟刪除 scope 自動排除。
        $rows = GuestApply::query()
            ->leftJoin('doorplates as d', 'd.id', '=', 'guest_applies.doorplate_id')
            ->whereIn('guest_applies.reservation_status', [GuestApply::STATUS_CHECKED_IN, GuestApply::STATUS_CHECKED_OUT])
            ->whereDate('guest_applies.visit_date', $day)
            ->groupBy('d.floor', 'guest_applies.doorplate_id', 'guest_applies.visit_reason', 'guest_applies.reservation_type')
            ->selectRaw('COALESCE(d.floor, 0) as floor, COALESCE(guest_applies.doorplate_id, 0) as doorplate_id, COALESCE(guest_applies.visit_reason, 0) as visit_reason, guest_applies.reservation_type, COUNT(*) as apply_count, SUM(guest_applies.guest_count) as visit_count')
            ->get();

        $now = now();
        $insert = $rows->map(fn ($r) => [
            'stat_date' => $day,
            'floor' => (int) $r->floor,
            'doorplate_id' => (int) $r->doorplate_id,
            'visit_reason' => (int) $r->visit_reason,
            'reservation_type' => (int) $r->reservation_type,
            'apply_count' => (int) $r->apply_count,
            'visit_count' => (int) $r->visit_count,
            // 目前每位來訪者配發一張臨時卡；之後有實際發卡紀錄再改這個欄位的來源
            'card_count' => (int) $r->visit_count,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::connection('site_current')->transaction(function () use ($day, $insert) {
            GuestDailyStat::query()->whereDate('stat_date', $day)->delete();

            foreach (array_chunk($insert, 500) as $chunk) {
                GuestDailyStat::query()->insert($chunk);
            }
        });

        return count($insert);
    }

    /**
     * 統計總覽：五張卡片（含與上一個同長度期間比較）、趨勢、事由分布、樓層分布。
     *
     * @param  string  $groupBy  趨勢分組：day 或 month（查一年用 month）
     */
    public function overview(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason, string $groupBy = 'day'): array
    {
        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $current = $this->totals($from, $to, $floor, $doorplateId, $reason);
        $previous = $this->totals($prevFrom, $prevTo, $floor, $doorplateId, $reason);

        $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
        $growth = fn (int $cur, int $prev) => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;

        $cards = [
            'total' => ['count' => $current['total'], 'growth_rate' => $growth($current['total'], $previous['total'])],
            'reserved' => ['count' => $current['reserved'], 'percent' => $pct($current['reserved'], $current['total']), 'growth_rate' => $growth($current['reserved'], $previous['reserved'])],
            'onsite' => ['count' => $current['onsite'], 'percent' => $pct($current['onsite'], $current['total']), 'growth_rate' => $growth($current['onsite'], $previous['onsite'])],
            'companies' => ['count' => $current['companies'], 'growth_rate' => $growth($current['companies'], $previous['companies'])],
            'cards' => ['count' => $current['cards'], 'growth_rate' => $growth($current['cards'], $previous['cards'])],
        ];

        return [
            'cards' => $cards,
            'trend' => $this->trend($from, $to, $floor, $doorplateId, $reason, $groupBy),
            'reasons' => $this->reasonShare($from, $to, $floor, $doorplateId, $reason, $current['total']),
            'floors' => $this->floorShare($from, $to, $floor, $doorplateId, $reason, $current['total']),
        ];
    }

    /**
     * 統計明細查詢條件：一天一列（不分頁，分頁由 Controller 的 paginateOrGet 處理，匯出直接 get）。
     */
    public function details(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason): Builder
    {
        return $this->scope($from, $to, $floor, $doorplateId, $reason)
            ->selectRaw('stat_date, SUM(visit_count) as total, SUM(CASE WHEN reservation_type = 0 THEN visit_count ELSE 0 END) as reserved, SUM(CASE WHEN reservation_type = 1 THEN visit_count ELSE 0 END) as onsite, COUNT(DISTINCT CASE WHEN doorplate_id > 0 THEN doorplate_id END) as companies')
            ->groupBy('stat_date')
            ->orderByDesc('stat_date');
    }

    /**
     * 補上明細每一天「最主要來訪事由」（當天人次最多的事由）：對這批日期只查一次，不是每列一次。
     *
     * @param  array<int, string>  $dates  Y-m-d
     * @return array<string, array{value: int, label: string}> 日期 => 事由
     */
    public function topReasons(array $dates, ?int $floor, ?int $doorplateId, ?int $reason): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = GuestDailyStat::query()
            ->whereIn('stat_date', $dates)
            ->when($floor !== null, fn ($q) => $q->where('floor', $floor))
            ->when($doorplateId !== null, fn ($q) => $q->where('doorplate_id', $doorplateId))
            ->when($reason !== null, fn ($q) => $q->where('visit_reason', $reason))
            ->selectRaw('stat_date, visit_reason, SUM(visit_count) as c')
            ->groupBy('stat_date', 'visit_reason')
            ->get();

        $top = [];
        foreach ($rows->groupBy(fn ($r) => $r->stat_date->toDateString()) as $day => $items) {
            // 人次相同取事由值小的，結果才穩定
            $best = $items->sortBy([['c', 'desc'], ['visit_reason', 'asc']])->first();
            $top[$day] = ['value' => (int) $best->visit_reason, 'label' => $this->reasons->label((int) $best->visit_reason)];
        }

        return $top;
    }

    private function scope(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason): Builder
    {
        return GuestDailyStat::query()
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->when($floor !== null, fn ($q) => $q->where('floor', $floor))
            ->when($doorplateId !== null, fn ($q) => $q->where('doorplate_id', $doorplateId))
            ->when($reason !== null, fn ($q) => $q->where('visit_reason', $reason));
    }

    /** 期間合計。來訪公司數是整段期間不重複的公司，不能把每天的公司數相加 */
    private function totals(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason): array
    {
        $row = $this->scope($from, $to, $floor, $doorplateId, $reason)
            ->selectRaw('COALESCE(SUM(visit_count),0) as total, COALESCE(SUM(CASE WHEN reservation_type = 0 THEN visit_count ELSE 0 END),0) as reserved, COALESCE(SUM(CASE WHEN reservation_type = 1 THEN visit_count ELSE 0 END),0) as onsite, COALESCE(SUM(card_count),0) as cards, COUNT(DISTINCT CASE WHEN doorplate_id > 0 THEN doorplate_id END) as companies')
            ->first();

        return [
            'total' => (int) $row->total,
            'reserved' => (int) $row->reserved,
            'onsite' => (int) $row->onsite,
            'cards' => (int) $row->cards,
            'companies' => (int) $row->companies,
        ];
    }

    /** 趨勢：沒資料的日期／月份補 0，折線圖才不會斷掉 */
    private function trend(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason, string $groupBy): array
    {
        $month = $groupBy === 'month';
        $expr = $month ? "DATE_FORMAT(stat_date, '%Y-%m')" : "DATE_FORMAT(stat_date, '%Y-%m-%d')";

        $counts = $this->scope($from, $to, $floor, $doorplateId, $reason)
            ->selectRaw("{$expr} as period, SUM(visit_count) as c")
            ->groupBy('period')
            ->pluck('c', 'period');

        $result = [];
        $cursor = $month ? $from->copy()->startOfMonth() : $from->copy();
        while ($cursor->lte($to)) {
            $key = $cursor->format($month ? 'Y-m' : 'Y-m-d');
            $result[] = ['date' => $key, 'count' => (int) ($counts[$key] ?? 0)];
            $month ? $cursor->addMonthNoOverflow() : $cursor->addDay();
        }

        return $result;
    }

    private function reasonShare(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason, int $total): array
    {
        return $this->scope($from, $to, $floor, $doorplateId, $reason)
            ->selectRaw('visit_reason, SUM(visit_count) as c')
            ->groupBy('visit_reason')
            ->orderByDesc('c')->orderBy('visit_reason')
            ->get()
            ->map(fn ($r) => [
                'visit_reason' => (int) $r->visit_reason,
                'label' => $this->reasons->label((int) $r->visit_reason),
                'count' => (int) $r->c,
                'percent' => $total > 0 ? round($r->c / $total * 100, 1) : 0.0,
            ])->all();
    }

    private function floorShare(Carbon $from, Carbon $to, ?int $floor, ?int $doorplateId, ?int $reason, int $total): array
    {
        return $this->scope($from, $to, $floor, $doorplateId, $reason)
            ->where('floor', '<>', 0)
            ->selectRaw('floor, SUM(visit_count) as c')
            ->groupBy('floor')
            ->orderByDesc('c')->orderBy('floor')
            ->limit(self::TOP_FLOORS)
            ->get()
            ->map(fn ($r) => [
                'floor' => (int) $r->floor,
                'floor_label' => self::floorLabel((int) $r->floor),
                'count' => (int) $r->c,
                'percent' => $total > 0 ? round($r->c / $total * 100, 1) : 0.0,
            ])->all();
    }

    /** 樓層顯示：正數 5 → 5F，負數 -1 → B1 */
    public static function floorLabel(int $floor): string
    {
        return $floor < 0 ? 'B'.abs($floor) : $floor.'F';
    }
}
