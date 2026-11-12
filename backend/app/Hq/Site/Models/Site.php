<?php

/* *****************************************
File: Site.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站開站記錄，見 README 6.2。
***************************************** */

namespace App\Hq\Site\Models;

use App\Hq\SiteModule\Models\Module;
use Illuminate\Database\Eloquent\Model;

// 子站開站記錄，見 README 6.2。存在主站自己的資料庫。
class Site extends Model
{
    // 明確寫死連線，見 Module 的註解說明。
    protected $connection = 'mysql';

    protected $primaryKey = 'site_id';

    protected $fillable = [
        'code',
        'name',
        'primary_domain',
        'path_prefix',
        'db_connection',
        'db_name',
        'site_type',
        'template_namespace',
        'clone_from_site_id',
        'provision_status',
        'provision_failed_reason',
        'vue_build_mode',
        'last_migrated_at',
        'last_migration_batch',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'last_migrated_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'site_modules', 'site_id', 'module_code', 'site_id', 'code')
            ->withPivot(['enabled_at', 'disabled_at']);
    }
}
