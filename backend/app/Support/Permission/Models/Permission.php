<?php

/* *****************************************
File: Permission.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 權限目錄，見 README 7.4、12.4。
***************************************** */

namespace App\Support\Permission\Models;

use Illuminate\Database\Eloquent\Model;

// 權限目錄，見 README 7.4、12.4。由 permission:sync-api/sync-web 掃描產生。
class Permission extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'code',
        'module_code',
        'domain',
        'method',
        'path',
        'portal_keys',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'portal_keys' => 'array',
        ];
    }
}
