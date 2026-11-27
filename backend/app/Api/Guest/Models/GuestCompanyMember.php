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

    // 三個角色的權限碼：建立 company 端口的 migration（2026_11_27_180000）與 SiteBaselineSeeder
    // 共用這一份，要調整權限只改這裡，再重跑 baseline seed 覆寫既有角色。
    private const EMPLOYEE_CODES = [
        'api.guest.companyProfile',
        'api.guest.index', 'api.guest.show', 'api.guest.store', 'api.guest.update', 'api.guest.cancel',
        'api.guest.records', 'api.guest.recordShow', 'api.guest.recordStats',
    ];
    private const APPROVER_CODES = [
        'api.guest.statusChange', 'api.guest.approval', 'api.guest.approvalStatus',
    ];
    private const ADMIN_CODES = [
        'api.guest.companySettingUpdate',
        'api.guest.employeeIndex', 'api.guest.employeeStore', 'api.guest.employeeUpdate',
        'api.guest.employeeResetPassword', 'api.guest.employeeDestroy',
    ];

    /** 公司內身分 → company 端口角色的權限碼（上層身分包含下層的全部權限） */
    public static function rolePermissionCodes(): array
    {
        return [
            self::TYPE_ADMIN => [...self::EMPLOYEE_CODES, ...self::APPROVER_CODES, ...self::ADMIN_CODES],
            self::TYPE_APPROVER => [...self::EMPLOYEE_CODES, ...self::APPROVER_CODES],
            self::TYPE_EMPLOYEE => self::EMPLOYEE_CODES,
        ];
    }

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

    /**
     * 能否修改／取消這張預約單：公司管理員、審核者可處理公司內所有預約單；
     * 一般員工只能處理自己建立的（created_by 為自己），不能動同事的預約。
     * （公司範圍已由 GuestApply 全域範圍限制，這裡只判斷公司內身分。）
     */
    public function canModifyApply(GuestApply $apply): bool
    {
        if ($this->member_type !== self::TYPE_EMPLOYEE) {
            return true;
        }

        return $apply->created_by !== null && $apply->created_by === $this->user_id;
    }
}
