<?php

/* *****************************************
File: UserPortalAccess.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 端口存取中介表，見 README 7.2。
***************************************** */

namespace App\Api\User\Models;

use App\Api\Role\Models\Role;
use App\Support\Portal\Models\Portal;
use Illuminate\Database\Eloquent\Model;

// 端口存取中介表，見 README 7.2。
class UserPortalAccess extends Model
{
    protected $connection = 'site_current';

    protected $table = 'user_portal_access';

    protected $fillable = [
        'user_id',
        'portal_id',
        'role_id',
        'is_portal_admin',
    ];

    protected function casts(): array
    {
        return [
            'is_portal_admin' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function portal()
    {
        return $this->belongsTo(Portal::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
