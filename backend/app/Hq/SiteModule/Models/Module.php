<?php

/* *****************************************
File: Module.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 模塊登記清單，見 README 6.9。
***************************************** */

namespace App\Hq\SiteModule\Models;

use Illuminate\Database\Eloquent\Model;

// 模塊登記清單，見 README 6.9。
class Module extends Model
{
    // 明確寫死連線，不依賴「目前的預設連線」——site:create/db:seed 等流程
    // 會暫時把 config('database.default') 切成 site_current，若這裡不寫死
    // 會被連帶影響，見對話紀錄修正的一個實際踩雷案例。
    protected $connection = 'mysql';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_core',
        'sort',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
