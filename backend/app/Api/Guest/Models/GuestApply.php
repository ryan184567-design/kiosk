<?php

/* *****************************************
File: GuestApply.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-17
Description: 訪客預約單 Model（子站庫，固定使用 site_current 連線）
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestApply extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** 審核狀態：0=待審核, 1=核准, 2=駁回, 3=已取消 */
    public const AUDIT_PENDING = 0;
    public const AUDIT_APPROVED = 1;
    public const AUDIT_REJECTED = 2;
    public const AUDIT_CANCELLED = 3;

    /** 預約類型：1=事前預約, 2=現場預約 */
    public const BOOKING_ADVANCE = 1;
    public const BOOKING_ONSITE = 2;

    // 應用層一律用 site_current，不在 Model 處理實際 site_id（見 DEVELOP.MD 6.4）
    protected $connection = 'site_current';

    protected $table = 'guest_applies';

    protected $fillable = [
        'applies_no', 'doorplate_id', 'date', 'time', 'visitor_count', 'host_name',
        'purpose', 'notes', 'email_notify', 'sms_notify', 'reminder_enabled', 'reminder_time',
        'qr_code', 'visit_code', 'booking_type', 'check_in_at', 'check_out_at', 'audit_status',
    ];

    protected $casts = [
        'doorplate_id' => 'integer',
        'date' => 'date:Y-m-d',
        'visitor_count' => 'integer',
        'email_notify' => 'boolean',
        'sms_notify' => 'boolean',
        'reminder_enabled' => 'boolean',
        'reminder_time' => 'integer',
        'booking_type' => 'integer',
        'audit_status' => 'integer',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
    ];

    /** 來訪者明細 */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'guest_applies_id');
    }
}
