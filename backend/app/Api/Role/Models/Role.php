<?php

/* *****************************************
File: Role.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色，見 README 7.2、7.4。
***************************************** */

namespace App\Api\Role\Models;

use App\Support\Portal\Models\Portal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// 角色，見 README 7.2、7.4。歸屬於端口，不可跨端口共用。刪除為軟刪除
// （見開發約定第 8 點），name 的重複檢查改在 RoleService（見 migration
// 2024_01_03_000000 的說明：資料庫層唯一鍵會連同已刪除紀錄一起擋）。
class Role extends Model
{
    use SoftDeletes;

    protected $connection = 'site_current';

    protected $fillable = [
        'portal_id',
        'name',
        'permission_codes',
    ];

    protected function casts(): array
    {
        return [
            'permission_codes' => 'array',
        ];
    }

    public function portal()
    {
        return $this->belongsTo(Portal::class);
    }
}
