<?php

/* *****************************************
File: GuestCompanyMember.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司成員：使用者隸屬哪家公司（門牌）與公司內身分（公司管理員／審核者／一般員工）。
***************************************** */

namespace App\Api\Guest\Models;

use App\Api\DataSetting\Models\Doorplate;
use App\Api\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestCompanyMember extends Model
{
    use SoftDeletes;

    public const TYPE_ADMIN = 1;      // 公司管理員：管理員工、審核設定、審核預約
    public const TYPE_APPROVER = 2;   // 審核者：審核預約
    public const TYPE_EMPLOYEE = 3;   // 一般員工：登記訪客

    // 公司內身分對應 company 端口的角色名稱（SiteBaselineSeeder::seedGuestCompanyPortal 建立）。
    // 指派身分時依名稱找角色，所以後台不要改這三個角色的名稱。
    public const ROLE_NAMES = [
        self::TYPE_ADMIN => '公司管理員',
        self::TYPE_APPROVER => '審核者',
        self::TYPE_EMPLOYEE => '一般員工',
    ];

    protected $connection = 'site_current';

    protected $table = 'guest_company_members';

    protected $fillable = ['user_id', 'doorplate_id', 'member_type'];

    protected $casts = [
        'user_id' => 'integer',
        'doorplate_id' => 'integer',
        'member_type' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doorplate(): BelongsTo
    {
        return $this->belongsTo(Doorplate::class, 'doorplate_id');
    }

    public function canApprove(): bool
    {
        return in_array($this->member_type, [self::TYPE_ADMIN, self::TYPE_APPROVER], true);
    }
}
