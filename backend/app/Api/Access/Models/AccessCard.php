<?php

namespace App\Api\Access\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class AccessCard extends Model
{
    use SoftDeletes;

    /** 預約類型 reservation_type：0=事前預約, 1=現場預約 */
    public const STATUS_FREE = 0;
    public const STATUS_USED = 1;
    protected $connection = 'site_current';

    protected $table = 'access_cards';

    protected $fillable = [
        'id',
        'card_number',
        'access_number',
        'status',
        'person_id',
        'reserve_date',
    ];
}
