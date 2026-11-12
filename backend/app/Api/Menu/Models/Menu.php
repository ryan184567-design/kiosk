<?php

/* *****************************************
File: Menu.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單，見 README 7.3。
***************************************** */

namespace App\Api\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// 選單，見 README 7.3。刪除為軟刪除（見開發約定第 8 點）。
class Menu extends Model
{
    use SoftDeletes;

    protected $connection = 'site_current';

    protected $fillable = [
        'portal_key',
        'module_code',
        'parent_id',
        'key',
        'title',
        'subtitle',
        'icon',
        'path',
        'target',
        'permission_code',
        'login_only',
        'sort',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'login_only' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort');
    }
}
