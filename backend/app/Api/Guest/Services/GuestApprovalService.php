<?php

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\GuestApply;
use Illuminate\Contracts\Database\Eloquent\Builder;

class GuestApprovalService
{

    /**
     * 預約單列表
     */
    public function list(
        ?string $date_from = null,
        ?string $date_to = null,
        ?int $status = null,
        ?string $keyword = null) :Builder
    {
        $query = GuestApply::query()
            ->with(['guests', 'doorplate:id,company_name,floor'])
            ->orderByDesc('created_at');


        if (! empty($keyword)) {
            $kw = '%'.$keyword.'%';
            $query->where(function ($q) use ($kw) {
                $q->where('reservation_id', 'like', $kw)
                    ->orWhere('host_name', 'like', $kw)
                    ->orWhereHas('guests', fn ($g) => $g->where('name', 'like', $kw));
            });
        }
        if (! empty($date_from)) {
            $query->whereDate('visit_date', '>=', $date_from);
        }
        if (! empty($date_to)) {
            $query->whereDate('visit_date', '<=', $date_to);
        }
        if (isset($status) && $status !== '') {
            $query->where('reservation_status', (int) $status);
        }

        return $query;
    }


    /**
     * 目前待審核、已核准、已拒絕的數量
     * @return array
     */
    public function status(): array
    {
        $statuses = [0, 1, 10, 11, 70];

        $counts = GuestApply::query()
            ->selectRaw('reservation_status, COUNT(1) as total')
            ->whereIn('reservation_status', $statuses)
            ->groupBy('reservation_status')
            ->pluck('total', 'reservation_status');

        $result = [];
        foreach ($statuses as $code) {
            $result[$code] = (int) ($counts[$code] ?? 0);
        }

        // 待審核(0)與修改待審核(1)加總，已核准(10)與修改已核准(11)數字加總
        $result[0] += $result[1];
        $result[10] += $result[11];
        unset($result[1], $result[11]);

        return $result;
    }

    /**
     * 今日訪客數量
     * 未到(30)、已取消(60)、已拒絕(70)不算進數量裡
     * @return int
     */
    public function todayGuest(): int
    {
        $now = date('Y-m-d');
        return (int) GuestApply::query()
            ->where('visit_date', $now)
            ->whereIn('reservation_status', [0, 1, 10, 11, 20, 40, 50])
            ->sum('guest_count');
    }

    /**
     * 這週訪客數量
     * 未到(30)、已取消(60)、已拒絕(70)不算進數量裡
     * @return int
     */
    public function weeklyGuest(): int
    {
        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));

        return (int) GuestApply::query()
            ->whereBetween('visit_date', [$monday, $sunday])
            ->whereIn('reservation_status', [0, 1, 10, 11, 20, 40, 50])
            ->sum('guest_count');
    }
}
