<?php


namespace App\Api\Guest\Services;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Models\GuestSlot;
use App\Support\Dashboard\DashboardReporter;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

    public function create(int $doorplate_id, string $date, string $start_time, string $end_time, int $visitor_count, string $host_name, int $purpose, string $notes, bool $email_notify, bool $sms_notify, bool $reminder_enabled, int $reminder_time, array $visitors, int $reservation_type, int $reservation_status): GuestApply
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
            'visit_reason' => $purpose,
            'remark' => $notes ?? '',
            'is_email_notify' => (bool) ($email_notify ?? false),
            'is_sms_notify' => (bool) ($sms_notify ?? false),
            'is_auto_remind' => (bool) ($reminder_enabled ?? false),
            // 沒開啟提醒時不保留分鐘數，避免殘留舊值
            'remind_time' => ! empty($reminder_enabled) ? ($reminder_time ?? 0) : 0,
            'reservation_id' => $this->nextAppliesNo(),
            'qr_code' => $this->uniqueCode('qr_code', fn () => Str::random(40)),
            'fast_pass_code' => $this->uniqueCode('fast_pass_code', fn () => (string) random_int(10000000, 99999999)),
            'reservation_type' => $reservation_type,
            'reservation_status' => $reservation_status,
            'consent_form' => 0,
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
     * 修改預約單：整批覆寫來訪者明細；不重新產生單號與驗證碼。
     */
    public function update(int $id, int $doorplate_id, string $date, string $start_time, string $end_time, int $visitor_count, string $host_name, int $purpose, string $notes, bool $email_notify, bool $sms_notify, bool $reminder_enabled, int $reminder_time, array $visitors): GuestApply
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
            'visit_reason' => $purpose,
            'remark' => $notes ?? null,
            'is_email_notify' => (bool) ($email_notify ?? false),
            'is_sms_notify' => (bool) ($sms_notify ?? false),
            'is_auto_remind' => (bool) ($reminder_enabled ?? false),
            // 沒開啟提醒時不保留分鐘數，避免殘留舊值
            'remind_time' => ! empty($reminder_enabled) ? ($reminder_time ?? null) : null,
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

    public function delete(int $id): void
    {
        $apply = GuestApply::query()->find($id);
        if (! $apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'));
        }

        $apply->delete();
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

        DashboardReporter::cancelled($apply);   // 上報儀錶板：取消

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

        // 上報儀錶板：審核通過（10 核准、11 修改後重新核准）
        if ((int) $reservation_status === $status) {
            // 狀態沒變（重複送出同一個狀態）不重複上報
        } elseif ($status === GuestApply::STATUS_APPROVED) {
            DashboardReporter::approved($apply);
        } elseif ($status === GuestApply::STATUS_EDIT_APPROVED) {
            DashboardReporter::modified($apply);
        }

        return $apply;
    }

    /**
     * 以速通碼查詢「今天、已通知」的預約單。
     */
    public function check(string $check_value)
    {
        $apply = GuestApply::query()
            ->with(['guests', 'doorplate:id,company_name,floor'])
            ->where('reservation_status', GuestApply::STATUS_NOTIFIED)
            ->where('visit_date', today()->toDateString())
            ->orderBy('visit_start_time')
            ->where('fast_pass_code', trim($check_value))
            ->first();

        if (! $apply) {
            throw new RuntimeException(__('api.guest_apply.not_found'), 404);
        }

        return $apply;
    }

    /**
     * 訪客報到後資料寫入與回傳
     */
    public function checkDone(int $id, int $slot, int $consentForm)
    {
        $apply = DB::connection('site_current')->transaction(function () use ($id, $slot, $consentForm) {
            // 先鎖預約單，避免同一筆被同時報到兩次
            $apply = GuestApply::query()->lockForUpdate()->find($id);
            if (! $apply) {
                throw new RuntimeException(__('api.guest_apply.not_found'), 404);
            }
            if ($apply->reservation_status !== GuestApply::STATUS_NOTIFIED) {
                throw new RuntimeException(__('api.guest_apply.not_checkable'), 404);
            }

            // 再鎖證件櫃，不存在與已占用分開處理
            $cardSlot = GuestSlot::query()->lockForUpdate()->find($slot);
            if (! $cardSlot) {
                throw new RuntimeException(__('api.guest_slot.not_found'), 404);
            }
            if ((int) $cardSlot->status !== GuestSlot::STATUS_FREE) {
                throw new RuntimeException(__('api.guest_slot.in_use'), 404);
            }

            $cardSlot->update([
                'reservation_id' => $apply->reservation_id,
                'status' => GuestSlot::STATUS_USED,
            ]);

            $apply->update([
                'id_card_slot_no' => $cardSlot->name,
                'consent_form' => $consentForm,
                'reservation_status' => GuestApply::STATUS_CHECKED_IN,
                'check_in_at' => now(),
                // 門禁卡號之後確認：先不寫入，保持 null，避免假值混進查詢欄位
            ]);

            return $apply;
        });

        DashboardReporter::checkin($apply);   // 上報儀錶板：報到（交易已提交）

        return $apply;
    }

    public function leave(int $card_no){

        $apply = DB::connection('site_current')->transaction(function () use ($card_no) {
            $apply = GuestApply::query()->where('reservation_status', GuestApply::STATUS_CHECKED_IN)->where('access_card_no', $card_no)->first();

            if (! $apply) {
                throw new RuntimeException(__('api.guest_apply.not_found'), 404);
            }

            $cardSlot = GuestSlot::query()->where('name', $apply->id_card_slot_no)->first();

            if (! $cardSlot) {
                throw new RuntimeException(__('api.guest_slot.not_found'), 404);
            }

            $cardSlot->update([
                'reservation_id' => null,
                'status' => GuestSlot::STATUS_FREE,
            ]);

            $apply->update([
                'id_card_slot_no' => null,
                'fast_pass_code' => null,
                'reservation_status' => GuestApply::STATUS_CHECKED_OUT,
                'check_out_at' => now(),
                // 門禁卡號之後確認：先不寫入，保持 null，避免假值混進查詢欄位
            ]);

            return $apply;
        });

        DashboardReporter::checkout($apply);   // 上報儀錶板：離場（交易已提交）

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
