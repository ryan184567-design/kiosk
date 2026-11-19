<?php

namespace App\Api\Guest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestSlot extends Model
{
    use SoftDeletes;
    protected $connection = 'site_current';

    protected $table = 'guest_slots';

    protected $fillable = [
        'name',
        'remark',
        'status'
    ];

}
