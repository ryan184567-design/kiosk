<?php

/* *****************************************
File: IdentityType.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 身份別，見 README 7.1。
***************************************** */

namespace App\Support\Identity\Models;

use App\Api\User\Models\User;
use Illuminate\Database\Eloquent\Model;

// 身份別，見 README 7.1。
class IdentityType extends Model
{
    protected $connection = 'site_current';

    protected $fillable = [
        'code',
        'name',
        'is_system',
        'is_delegation_root',
        'sort',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_delegation_root' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    // 見 README 7.1 第 4 點：對其他身份別完全隱藏。呼叫方需自行判斷
    // $viewer 是否為超級管理員，是的話改用不加此 scope 的查詢。
    public function scopeVisibleTo($query, ?User $viewer)
    {
        if ($viewer?->identityType?->is_system) {
            return $query;
        }

        return $query->where('is_system', false);
    }
}
