<?php

/* *****************************************
File: FactoryApp.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 廠商模式（App Token）的 App（機器／第三方呼叫方），見 docs/APP_DEVELOP.MD 9 節
***************************************** */

namespace App\Api\Factory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\HttpFoundation\IpUtils;

class FactoryApp extends Model
{
    use SoftDeletes;

    protected $connection = 'site_current';

    protected $table = 'factory_apps';

    protected $fillable = ['app_id', 'name', 'app_secret', 'status', 'is_debug', 'allowed_ips', 'expired_at', 'remark', 'last_used_at'];

    // app_secret 絕不輸出明文
    protected $hidden = ['app_secret'];

    protected $casts = [
        'app_secret' => 'encrypted',
        'status' => 'boolean',
        'is_debug' => 'boolean',
        'allowed_ips' => 'array',
        'expired_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function scopeRows(): HasMany
    {
        return $this->hasMany(FactoryAppScope::class, 'factory_app_id');
    }

    /** 啟用且未過期 */
    public function isUsable(): bool
    {
        return $this->status && (! $this->expired_at || $this->expired_at->isFuture());
    }

    /** 未設白名單代表不限制 */
    public function ipAllowed(?string $ip): bool
    {
        $list = $this->allowed_ips;

        if (empty($list)) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $list);
    }
}
