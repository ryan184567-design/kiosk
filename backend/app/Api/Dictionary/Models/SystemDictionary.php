<?php

/* *****************************************
File: SystemDictionary.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典（類型），見 README 17.3。
***************************************** */

namespace App\Api\Dictionary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 資料字典（類型），見 README 17.3。
class SystemDictionary extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'site_id',
        'code',
        'name',
        'description',
        'is_enabled',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SystemDictionaryItem::class, 'dictionary_id');
    }
}
