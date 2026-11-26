<?php

namespace App\Api\Access\Models;


use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class AccessFloor extends Model
{
    use SoftDeletes;
    protected $connection = 'site_current';

    protected $table = 'access_floor';

    protected $fillable = [
        'id', 'floor', 'access_control_list', 'floor_list', 'remark', 'status',
    ];

}
