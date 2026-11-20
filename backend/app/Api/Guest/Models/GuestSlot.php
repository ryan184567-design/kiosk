<?php

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestSlot extends Model
{
    use SoftDeletes;

    /** 使用類型 status: 0=未使用, 1=使用中 */
    public const STATUS_FREE = 0;
    public const STATUS_USED = 1;
    protected $connection = 'site_current';

    protected $table = 'guest_slots';

    protected $fillable = [
        'name',
        'reservation_id',
        'remark',
        'status'
    ];

}
