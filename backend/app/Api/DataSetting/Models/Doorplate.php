<?php

namespace App\Api\DataSetting\Models;

use Illuminate\Database\Eloquent\Model;

class Doorplate extends Model
{
    protected $connection = 'doorplates';

    protected $fillable = [
        'id',
        'doorplate',
        'floor',
        'household',
        'status',
    ];
}
