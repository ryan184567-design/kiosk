<?php

namespace App\Api\Access\Models;


use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class AccessSetting extends Model
{
    use SoftDeletes;
    protected $connection = 'site_current';

    protected $table = 'access_settings';

    protected $fillable = [
        'id', 'name', 'url', 'type', 'status',
    ];
}
