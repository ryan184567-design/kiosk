<?php

/* *****************************************
File: GuestApply.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-18
Description: 訪客預約單 Model（子站庫，固定使用 site_current 連線，軟刪除）
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Api\DataSetting\Models\Doorplate;
use App\Api\Guest\Support\GuestCompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestApply extends Model
{
    use HasFactory, SoftDeletes;

    /** 預約類型 reservation_type：0=事前預約, 1=現場預約 */
    public const TYPE_ADVANCE = 0;
    public const TYPE_ONSITE = 1;

    /** 預約單狀態 reservation_status */
    public const STATUS_PENDING = 0;            // 待審核
    public const STATUS_EDIT_PENDING = 1;       // 修改待審核
    public const STATUS_APPROVED = 10;          // 已核准
    public const STATUS_EDIT_APPROVED = 11;     // 修改已核准
    public const STATUS_NOTIFIED = 20;          // 已通知
    public const STATUS_NO_SHOW = 30;           // 未到
    public const STATUS_CHECKED_IN = 40;        // 已到
    public const STATUS_CHECKED_OUT = 50;       // 已離場
    public const STATUS_CANCELLED = 60;         // 已取消
    public const STATUS_REJECTED = 70;          // 已拒絕

    // 應用層一律用 site_current，不在 Model 處理實際 site_id（見 DEVELOP.MD 6.4）
    protected $connection = 'site_current';

    protected $table = 'guest_applies';

    protected $fillable = [
        'reservation_id', 'reservation_type', 'doorplate_id', 'host_name',
        'visit_date', 'visit_start_time', 'visit_end_time', 'guest_count',
        'visit_reason', 'remark',
        'is_email_notify', 'is_sms_notify', 'is_auto_remind', 'remind_time',
        'consent_form', 'id_card_slot_no',
        'qr_code', 'fast_pass_code', 'access_card_no',
        'check_in_at', 'check_out_at', 'reservation_status', 'created_by',
    ];

    // visit_start_time／visit_end_time 是 MySQL time 欄位，讀出來是 "HH:mm:ss" 字串，不做 cast；
    // 需要 "HH:mm" 給前端時，在 Controller 的 present 方法截成前 5 碼。
    protected $casts = [
        'reservation_type' => 'integer',
        'doorplate_id' => 'integer',
        'visit_date' => 'date:Y-m-d',
        'guest_count' => 'integer',
        'is_email_notify' => 'boolean',
        'is_sms_notify' => 'boolean',
        'is_auto_remind' => 'boolean',
        'remind_time' => 'integer',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'reservation_status' => 'integer',
        'created_by' => 'integer',
    ];

    // 公司端口的資料隔離：登入 company 端口時（GuestCompanyContext 有值），所有預約單查詢
    // 一律只看得到自己公司（門牌）的資料——列表、詳情、修改、審核、訪客記錄、統計都經過
    // 這裡，不必在每支 Service 各自加條件。admin 端口、訪客機、CLI 不受影響。
    protected static function booted(): void
    {
        static::addGlobalScope('guest_company', function (Builder $query) {
            $doorplateId = GuestCompanyContext::doorplateId();

            if ($doorplateId !== null) {
                $query->where($query->getModel()->qualifyColumn('doorplate_id'), $doorplateId);
            }
        });
    }

    /** 來訪者明細（SoftDeletes 預設只取未刪除的） */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'guest_applies_id');
    }

    /** 受訪公司（門牌），含已軟刪除的門牌，避免歷史預約單顯示不出公司 */
    public function doorplate(): BelongsTo
    {
        return $this->belongsTo(Doorplate::class, 'doorplate_id')->withTrashed();
    }
}
