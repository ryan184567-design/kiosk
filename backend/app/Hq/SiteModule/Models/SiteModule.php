<?php

/* *****************************************
File: SiteModule.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站模塊開通中介表，見 README 6.9 第 2 點。
***************************************** */

namespace App\Hq\SiteModule\Models;

use App\Hq\Site\Models\Site;
use Illuminate\Database\Eloquent\Model;

// 子站模塊開通中介表，見 README 6.9 第 2 點。
class SiteModule extends Model
{
    // 明確寫死連線，見 Module 的註解說明。
    protected $connection = 'mysql';

    protected $table = 'site_modules';

    protected $fillable = [
        'site_id',
        'module_code',
        'enabled_at',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id', 'site_id');
    }

    public function module()
    {
        return $this->belongsTo(Module::class, 'module_code', 'code');
    }
}
