<?php

/* *****************************************
File: Guest.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-17
Description: 訪客預約的來訪者明細 Model
***************************************** */

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use SoftDeletes;
    protected $connection = 'site_current';

    protected $table = 'guests';

    protected $fillable = [
        'guest_applies_id', 'name', 'company_name', 'title', 'phone', 'email', 'plate_number',
    ];

    /** 所屬預約單 */
    public function apply(): BelongsTo
    {
        return $this->belongsTo(GuestApply::class, 'guest_applies_id');
    }
}
