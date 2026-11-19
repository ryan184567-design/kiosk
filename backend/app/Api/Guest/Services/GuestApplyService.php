<?php


namespace App\Api\Guest\Services;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

use RuntimeException;
class GuestApplyService
{
    /** 可以修改／取消的狀態：待審核、核准 */
    private const EDITABLE_STATUSES = [
        GuestApply::STATUS_PENDING,
        GuestApply::STATUS_EDIT_PENDING,
        GuestApply::STATUS_APPROVED,
        GuestApply::STATUS_EDIT_APPROVED,
    ];

    /** 取消、拒絕的狀態，將QRcode改為null */
    private const QRCODENULL_STATUSES = [
        GuestApply::STATUS_CANCELLED,
        GuestApply::STATUS_REJECTED,
    ];

    private const CANCELLABLE_STATUSES = self::EDITABLE_STATUSES;

    /**
     * 預約單查詢條件（不分頁，分頁由 Controller 的 paginateOrGet 處理）。
     */
    public function applies(?string $keyword = null, ?string $date_from = null, ?string $date_to = null, ?int $status = null): Builder
    {
        $query = GuestApply::query()->with(['guests', 'doorplate:id,company_name,floor'])
            ->orderByDesc('visit_date')->orderByDesc('visit_start_time')->orderByDesc('id');

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

    public function create(int $doorplate_id, string $date, string $start_time, string $end_time, int $visitor_count, string $host_name, string $purpose, string $notes, bool $email_notify, bool $sms_notify, bool $reminder_enabled, int $reminder_time, array $visitors): GuestApply
    {
        $this->assertTimeRange($start_time, $end_time);
        $this->assertVisitorCount((int) $visitor_count, $visitors);

        $apply = GuestApply::query()->create([
            'doorplate_id' => $doorplate_id,
            'visit_date' => $date,
            'visit_start_time' => $start_time,
            'visit_end_time' => $end_time,
            'guest_count' => $visitor_count,
            'host_name' => $host_name,
            'visit_reason' => $purpose ?? null,
            'remark' => $notes ?? null,
            'is_email_notify' => (bool) ($email_notify ?? false),
            'is_sms_notify' => (bool) ($sms_notify ?? false),
            'is_auto_remind' => (bool) ($reminder_enabled ?? false),
            // 沒開啟提醒時不保留分鐘數，避免殘留舊值
            'remind_time' => ! empty($reminder_enabled) ? ($reminder_time ?? null) : null,
            'reservation_id' => $this->nextAppliesNo(),
            'qr_code' => $this->uniqueCode('qr_code', fn () => Str::random(40)),
            'fast_pass_code' => $this->uniqueCode('visit_code', fn () => (string) random_int(10000000, 99999999)),
            'reservation_type' => GuestApply::TYPE_ADVANCE,
            'reservation_status' => GuestApply::STATUS_PENDING,
        ]);

        foreach ($visitors as $v) {
            Guest::query()->create([
                'guest_applies_id' => $apply->id,
                'name' => $v['name'],
                'company_name' => $v['company_name'] ?? null,
                'title' => $v['title'] ?? null,
                'phone' => $v['phone'],
                'email' => $v['email'] ?? null,
                'plate_number' => $v['plate_number'] ?? null,
            ]);
        }

        return $apply;
    }

    /**
     * 查看預約單：查看單筆預約單資料
     */
    public function show(int $id){

        // 'doorplate:id,company_name,floor' 只取需要的欄位；id 一定要選，否則關聯對不起來
        $apply = GuestApply::query()
            ->with(['guests', 'doorplate:id,company_name,floor'])
            ->find($id);

        if (! $apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'), 404);
        }

        return $apply;
    }
    /**
     * 修改預約單：整批覆寫來訪者明細；不重新產生單號與驗證碼，也不改審核狀態。
     */
    public function update(int $id, int $doorplate_id, string $date, string $start_time, string $end_time, int $visitor_count, string $host_name, string $purpose, string $notes, bool $email_notify, bool $sms_notify, bool $reminder_enabled, int $reminder_time, array $visitors): GuestApply
    {
        $this->assertTimeRange($start_time, $end_time);
        $this->assertVisitorCount((int) $visitor_count , $visitors);

        $apply = GuestApply::query()->find($id);
        if (! $apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'));
        }
        $this->assertMutable($apply, self::CANCELLABLE_STATUSES);

        $apply->update([
            'doorplate_id' => $doorplate_id,
            'visit_date' => $date,
            'visit_start_time' => $start_time,
            'visit_end_time' => $end_time,
            'guest_count' => $visitor_count,
            'host_name' => $host_name,
            'visit_reason' => $purpose ?? null,
            'remark' => $notes ?? null,
            'is_email_notify' => (bool) ($email_notify ?? false),
            'is_sms_notify' => (bool) ($sms_notify ?? false),
            'is_auto_remind' => (bool) ($reminder_enabled ?? false),
            // 沒開啟提醒時不保留分鐘數，避免殘留舊值
            'remind_time' => ! empty($reminder_enabled) ? ($reminder_time ?? null) : null,
            'reservation_id' => $this->nextAppliesNo(),
            'qr_code' => $this->uniqueCode('qr_code', fn () => Str::random(40)),
            'fast_pass_code' => $this->uniqueCode('fast_pass_code', fn () => (string) random_int(1000000000, 9999999999)),
            'reservation_status' => GuestApply::STATUS_EDIT_PENDING,
        ]);

        Guest::query()->where('guest_applies_id', $apply->id)->delete();

        foreach ($visitors as $v) {
            Guest::query()->create([
                'guest_applies_id' => $apply->id,
                'name' => $v['name'],
                'company_name' => $v['company_name'] ?? null,
                'title' => $v['title'] ?? null,
                'phone' => $v['phone'],
                'email' => $v['email'] ?? null,
                'plate_number' => $v['plate_number'] ?? null,
            ]);
        }

        return $apply;
    }

    /**
     * 取消預約單：reservation_status 設為 60，並清除 QR code 與速通碼、門禁卡號讓它們立刻失效。
     * 資料保留不刪除（取消不是刪除）。
     */
    public function cancel(int $id): GuestApply
    {
        $apply = GuestApply::query()->find($id);
        if (! $apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'));
        }

        $this->assertMutable($apply, self::EDITABLE_STATUSES);

        $apply->update([
            'reservation_status' => GuestApply::STATUS_CANCELLED,
            'qr_code' => null,
            'fast_pass_code' => null,
            'access_card_no' => null
        ]);

        return $apply;
    }

    /**
     * 修改預約單狀態
     */
    public function status(int $id, int $status)
    {
        $apply = GuestApply::query()->find($id);
        if(!$apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'));
        }

        // 依據原先審核狀態修改核准狀態
        $reservation_status = $apply->reservation_status;
        if($reservation_status == 0 && $status == 1) {
            $status = 10;
        } elseif($reservation_status == 1 && $status == 1) {
            $status = 11;
        }

        $updateData = [
            'reservation_status' => $status,
        ];

        if (in_array($status, self::QRCODENULL_STATUSES, true)) {
            $updateData['qr_code'] = null;
            $updateData['fast_pass_code'] = null;
            $updateData['access_card_no'] = null;
        }

        $apply->update($updateData);

        return $apply;
    }

    /** 時段格式為 HH:mm~HH:mm（格式已由 Controller 驗證），結束時間必須晚於開始時間 */
    private function assertTimeRange(string $start, string $end): void
    {
        if (strcmp($end, $start) <= 0) {
            throw new RuntimeException(__('api.guest_apply.invalid_time_range'));
        }
    }

    /** 名單人數不可超過「來訪人數」（來訪人數可大於名單，代表其餘同行者不另外登記） */
    private function assertVisitorCount(int $visitorCount, array $visitors): void
    {
        if (count($visitors) > $visitorCount) {
            throw new RuntimeException(__('api.guest_apply.visitor_count_mismatch', ['count' => count($visitors), 'max' => $visitorCount]));
        }
    }

    /** 已報到不可異動 */
    private function assertMutable(GuestApply $apply, array $allowedStatuses): void
    {
        if ($apply->check_in_at !== null) {
            throw new RuntimeException(__('api.guest_apply.already_checked_in'));
        }

        if (!in_array($apply->reservation_status, $allowedStatuses, true)) {
            throw new RuntimeException(__('api.guest_apply.status_not_editable'));
        }
    }

    /** 產生下一個單號：GA + 日期 + 當日 4 位流水號；唯一性最終由資料庫唯一索引保證 */
    private function nextAppliesNo(): string
    {
        $prefix = 'GA'.now()->format('Ymd');
        $last = GuestApply::query()
            ->where('reservation_id', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reservation_id')
            ->value('reservation_id');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** 產生不與現有資料重複的驗證碼 */
    private function uniqueCode(string $column, callable $generator): string
    {
        do {
            $code = $generator();
        } while (GuestApply::query()->where($column, $code)->exists());

        return $code;
    }

}
