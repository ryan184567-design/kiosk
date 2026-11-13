<?php

namespace App\Api\DataSetting\Models;

use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    protected $connection = 'floors';

    protected $fillable = [
        'id',
        'bid',
        'floor',
        'location',
        'image',
        'status',
    ];
}
