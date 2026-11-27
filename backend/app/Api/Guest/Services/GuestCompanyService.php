<?php

/* *****************************************
File: GuestCompanyService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 訪客模組公司端口：公司（門牌）審核設定、公司成員帳號（公司管理員／審核者／一般員工）管理。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Auth\Models\UserJwtSession;
use App\Api\DataSetting\Models\Doorplate;
use App\Api\Guest\Models\GuestCompanyMember;
use App\Api\Role\Models\Role;
use App\Api\User\Models\User;
use App\Api\User\Models\UserPortalAccess;
use App\Support\Audit\Models\AuditLog;
use App\Support\Identity\Models\IdentityType;
use App\Support\Portal\Models\Portal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 公司成員帳號就是一般的 users，差別只在：
//   1. 只開通 company 端口，角色依公司內身分固定對應（見 GuestCompanyMember::ROLE_NAMES）；
//   2. 另有一筆 guest_company_members 記錄所屬公司，資料範圍靠它（見 EnsureGuestCompanyScope）。
// 這裡不走 UserService::grantOrUpdatePortalAccess，因為那支的「授權上限」是以授權者在
// company 端口持有的權限判斷，admin 後台管理員本身沒有 company 端口權限會被擋下；
// 這裡的授權範圍改由「只能指派固定三種角色＋公司管理員只能管自己公司」保證。
class GuestCompanyService
{
    public const PORTAL_KEY = 'company';

    /** 公司管理員在公司端口內可以指派的身分（不能自行新增公司管理員，由後台建立） */
    public const COMPANY_ASSIGNABLE_TYPES = [
        GuestCompanyMember::TYPE_APPROVER,
        GuestCompanyMember::TYPE_EMPLOYEE,
    ];

    /**
     * 公司（有公司名稱的門牌）列表，附成員統計，給後台「公司帳號管理」用。
     */
    public function companies(?string $keyword = null): Builder
    {
        // 成員統計用子查詢，不在 Doorplate（DataSetting 模組）上加關聯，避免基本資料模組反過來依賴訪客模組。
        $members = fn (?int $type = null) => GuestCompanyMember::query()
            ->selectRaw('count(*)')
            ->whereColumn('guest_company_members.doorplate_id', 'doorplates.id')
            ->when($type !== null, fn ($q) => $q->where('member_type', $type));

        $query = Doorplate::query()
            ->select('doorplates.*')
            ->selectSub($members(), 'member_count')
            ->selectSub($members(GuestCompanyMember::TYPE_ADMIN), 'admin_count')
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->orderBy('floor')->orderBy('doorplate');

        if (! empty($keyword)) {
            $kw = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';
            $query->where('company_name', 'like', $kw);
        }

        return $query;
    }

    public function findCompany(int $doorplateId): Doorplate
    {
        $doorplate = Doorplate::query()->find($doorplateId);

        if (! $doorplate) {
            throw new RuntimeException(__('api.guest_company.company_not_found'), 404);
        }

        return $doorplate;
    }

    public function updateApprovalRequired(User $actor, Doorplate $doorplate, bool $required, ?string $ip = null): Doorplate
    {
        $doorplate->update(['approval_required' => $required]);

        $this->audit($actor, 'guest_company.approval_setting', "doorplate:{$doorplate->id}", ['approval_required' => $required], $ip);

        return $doorplate;
    }

    /**
     * 某家公司的成員查詢（不分頁，分頁由 Controller 處理）。
     */
    public function members(int $doorplateId, ?string $keyword = null, ?int $memberType = null): Builder
    {
        $query = GuestCompanyMember::query()
            ->with('user:id,username,name,email')
            ->where('doorplate_id', $doorplateId)
            ->orderBy('member_type')->orderBy('id');

        if (! empty($keyword)) {
            $kw = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';
            $query->whereHas('user', fn ($q) => $q->where('username', 'like', $kw)->orWhere('name', 'like', $kw));
        }

        if ($memberType !== null) {
            $query->where('member_type', $memberType);
        }

        return $query;
    }

    public function findMember(int $doorplateId, int $memberId): GuestCompanyMember
    {
        $member = GuestCompanyMember::query()->with('user')->where('doorplate_id', $doorplateId)->find($memberId);

        if (! $member || ! $member->user) {
            throw new RuntimeException(__('api.guest_company.member_not_found'), 404);
        }

        return $member;
    }

    /**
     * 建立公司成員：新帳號（身分別一般使用者）＋開通 company 端口（依身分指派角色）＋成員紀錄。
     */
    public function createMember(User $actor, Doorplate $doorplate, string $username, string $password, string $name, ?string $email, int $memberType, ?string $ip = null): GuestCompanyMember
    {
        if (User::query()->where('username', $username)->exists()) {
            throw new RuntimeException(__('api.user.username_exists'));
        }

        $portal = $this->portal();
        $role = $this->roleFor($portal, $memberType);
        $identityTypeId = IdentityType::query()->where('code', 'staff')->value('id');

        if (! $identityTypeId) {
            throw new RuntimeException(__('api.user.identity_type_invalid'));
        }

        $member = DB::connection('site_current')->transaction(function () use ($doorplate, $username, $password, $name, $email, $memberType, $portal, $role, $identityTypeId) {
            $user = User::query()->create([
                'username' => $username,
                'name' => $name,
                'email' => $email,
                'identity_type_id' => $identityTypeId,
                'password' => bcrypt($password),
            ]);

            UserPortalAccess::query()->create([
                'user_id' => $user->id,
                'portal_id' => $portal->id,
                'role_id' => $role->id,
                'is_portal_admin' => false,
            ]);

            return GuestCompanyMember::query()->create([
                'user_id' => $user->id,
                'doorplate_id' => $doorplate->id,
                'member_type' => $memberType,
            ]);
        });

        $this->audit($actor, 'guest_company.member_create', "user:{$member->user_id}", [
            'doorplate_id' => $doorplate->id,
            'username' => $username,
            'member_type' => $memberType,
        ], $ip);

        return $member->load('user');
    }

    /**
     * 修改成員姓名／Email／身分。身分變更會同步改 company 端口角色，並讓對方重新登入。
     */
    public function updateMember(User $actor, GuestCompanyMember $member, string $name, ?string $email, int $memberType, ?string $ip = null): GuestCompanyMember
    {
        $typeChanged = $member->member_type !== $memberType;

        if ($typeChanged) {
            $this->assertNotLastAdmin($member);
        }

        $portal = $this->portal();
        $role = $typeChanged ? $this->roleFor($portal, $memberType) : null;

        DB::connection('site_current')->transaction(function () use ($member, $name, $email, $memberType, $portal, $role) {
            $member->user->update(['name' => $name, 'email' => $email]);

            if ($role) {
                $member->update(['member_type' => $memberType]);

                UserPortalAccess::query()->updateOrCreate(
                    ['user_id' => $member->user_id, 'portal_id' => $portal->id],
                    ['role_id' => $role->id, 'is_portal_admin' => false]
                );
            }
        });

        if ($typeChanged) {
            // 角色變了：已登入的 session 選單快取還是舊角色，強制重新登入最單純。
            $this->deactivateSessions($member->user_id, $portal->id);
        }

        $this->audit($actor, 'guest_company.member_update', "user:{$member->user_id}", [
            'doorplate_id' => $member->doorplate_id,
            'member_type' => $memberType,
        ], $ip);

        return $member->refresh()->load('user');
    }

    public function resetMemberPassword(User $actor, GuestCompanyMember $member, string $password, ?string $ip = null): void
    {
        $member->user->update(['password' => bcrypt($password)]);

        // 審計不記密碼任何片段（見 UserService::resetPassword 同一規則）
        $this->audit($actor, 'guest_company.member_reset_password', "user:{$member->user_id}", ['doorplate_id' => $member->doorplate_id], $ip);
    }

    /**
     * 移除公司成員：成員紀錄軟刪除、收回 company 端口存取、讓已登入的 session 失效。
     * 使用者帳號本身保留（已建立的預約單 created_by 仍指得到人）。
     */
    public function removeMember(User $actor, GuestCompanyMember $member, ?string $ip = null): void
    {
        if ($member->user_id === $actor->id) {
            throw new RuntimeException(__('api.guest_company.cannot_remove_self'));
        }

        $this->assertNotLastAdmin($member);

        $portal = $this->portal();

        DB::connection('site_current')->transaction(function () use ($member, $portal) {
            UserPortalAccess::query()->where('user_id', $member->user_id)->where('portal_id', $portal->id)->delete();
            $member->delete();
        });

        $this->deactivateSessions($member->user_id, $portal->id);

        $this->audit($actor, 'guest_company.member_remove', "user:{$member->user_id}", ['doorplate_id' => $member->doorplate_id], $ip);
    }

    /** 公司管理員在公司端口內只能管理審核者與一般員工（不能動其他公司管理員，含自己的身分） */
    public function assertManageableByCompanyAdmin(GuestCompanyMember $member): void
    {
        if ($member->member_type === GuestCompanyMember::TYPE_ADMIN) {
            throw new RuntimeException(__('api.guest_company.cannot_manage_admin'));
        }
    }

    public function present(GuestCompanyMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'username' => $member->user?->username,
            'name' => $member->user?->name,
            'email' => $member->user?->email,
            'member_type' => $member->member_type,
            'created_at' => $member->created_at?->toDateTimeString(),
        ];
    }

    public function presentCompany(Doorplate $doorplate): array
    {
        return [
            'doorplate_id' => $doorplate->id,
            'company_name' => $doorplate->company_name,
            'floor' => $doorplate->floor,
            'doorplate' => $doorplate->doorplate,
            'approval_required' => (bool) ($doorplate->approval_required ?? true),
        ];
    }

    private function portal(): Portal
    {
        $portal = Portal::query()->where('portal_key', self::PORTAL_KEY)->first();

        if (! $portal) {
            throw new RuntimeException(__('api.guest_company.portal_missing'));
        }

        return $portal;
    }

    private function roleFor(Portal $portal, int $memberType): Role
    {
        $roleName = GuestCompanyMember::ROLE_NAMES[$memberType] ?? null;
        $role = $roleName ? Role::query()->where('portal_id', $portal->id)->where('name', $roleName)->first() : null;

        if (! $role) {
            throw new RuntimeException(__('api.guest_company.role_missing', ['name' => $roleName ?? $memberType]));
        }

        return $role;
    }

    // 每家公司至少要留一位公司管理員，否則就沒人能管理員工與審核設定。
    private function assertNotLastAdmin(GuestCompanyMember $member): void
    {
        if ($member->member_type !== GuestCompanyMember::TYPE_ADMIN) {
            return;
        }

        $admins = GuestCompanyMember::query()
            ->where('doorplate_id', $member->doorplate_id)
            ->where('member_type', GuestCompanyMember::TYPE_ADMIN)
            ->count();

        if ($admins <= 1) {
            throw new RuntimeException(__('api.guest_company.last_admin'));
        }
    }

    private function deactivateSessions(int $userId, int $portalId): void
    {
        UserJwtSession::query()
            ->where('user_id', $userId)
            ->where('portal_id', $portalId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function audit(User $actor, string $action, string $target, array $context, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => $target,
            'context' => $context,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
