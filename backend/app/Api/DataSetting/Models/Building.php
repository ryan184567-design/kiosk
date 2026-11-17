<?php

namespace App\Api\DataSetting\Models;

use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    protected $connection = 'site_current';
    protected $table = 'buildings';

    protected $fillable = [
        'id',
        'name',
        'on_floor',
        'under_floor',
        'status',
    ];
}
