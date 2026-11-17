<?php

namespace App\Api\DataSetting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doorplate extends Model
{
    use SoftDeletes;

    protected $connection = 'site_current';
    protected $table = 'doorplates';

    protected $fillable = [
        'id',
        'doorplate',
        'floor',
        'household',
        'company_name',
        'status',
    ];
}
