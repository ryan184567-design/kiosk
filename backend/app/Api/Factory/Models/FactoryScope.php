<?php

namespace App\Api\Factory\Models;

use Illuminate\Database\Eloquent\Model;

class FactoryScope extends Model
{
    protected $connection = 'site_current';

    protected $table = 'factory_scopes';

    protected $fillable = ['module', 'code', 'name', 'description'];
}
