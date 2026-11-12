<?php

/* *****************************************
File: SystemDictionaryItem.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典（項目），見 README 17.3。
***************************************** */

namespace App\Api\Dictionary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 資料字典（項目），見 README 17.3。
class SystemDictionaryItem extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'dictionary_id',
        'value',
        'label',
        'is_enabled',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(SystemDictionary::class, 'dictionary_id');
    }
}
