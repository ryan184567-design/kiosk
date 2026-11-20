<?php

namespace App\Api\Factory\Models;

use Illuminate\Database\Eloquent\Model;

class FactoryAppScope extends Model
{
    protected $connection = 'site_current';

    protected $table = 'factory_app_scopes';

    protected $fillable = ['factory_app_id', 'scope'];
}
