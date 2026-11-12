<?php

/* *****************************************
File: Portal.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 端口，見 README 7.2、9.1。
***************************************** */

namespace App\Support\Portal\Models;

use App\Api\Role\Models\Role;
use Illuminate\Database\Eloquent\Model;

// 端口，見 README 7.2、9.1。
class Portal extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'portal_key',
        'name',
        'admin_only',
        'is_enabled',
        'requires_login',
    ];

    protected function casts(): array
    {
        return [
            'admin_only' => 'boolean',
            'is_enabled' => 'boolean',
            'requires_login' => 'boolean',
        ];
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}
