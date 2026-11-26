<?php

namespace App\Api\Access\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class AccessCard extends Model
{
    use SoftDeletes;
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
