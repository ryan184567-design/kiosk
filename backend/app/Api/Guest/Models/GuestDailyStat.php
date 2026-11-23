<?php

/* *****************************************
File: GuestDailyStat.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客每日統計緩存 Model（子站庫，固定使用 site_current 連線），由 guest:stats-aggregate 每日寫入
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;

class GuestDailyStat extends Model
{
    protected $connection = 'site_current';

    protected $table = 'guest_daily_stats';

    protected $fillable = [
        'stat_date', 'floor', 'doorplate_id', 'visit_reason', 'reservation_type',
        'apply_count', 'visit_count', 'card_count',
    ];

    protected $casts = [
        'stat_date' => 'date:Y-m-d',
        'floor' => 'integer',
        'doorplate_id' => 'integer',
        'visit_reason' => 'integer',
        'reservation_type' => 'integer',
        'apply_count' => 'integer',
        'visit_count' => 'integer',
        'card_count' => 'integer',
    ];
}
