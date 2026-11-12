<?php

/* *****************************************
File: PortalAdminService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 端口管理業務邏輯，見 README 9.1。
***************************************** */

namespace App\Api\Portal\Services;

use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Portal\Models\Portal;
use Illuminate\Contracts\Database\Eloquent\Builder;
use RuntimeException;

// 端口管理業務邏輯，見 README 9.1。端口是基礎設施表（跟 SystemConfig／
// Dictionary 性質相同），不是「某個端口底下」的業務資料，所以不像
// Role/User/Menu 那樣需要 resolveTargetPortalId 那套跨端口 ceiling 判斷，
// 直接用 permission.api:{code} 檢查目前登入端口是否持有權限即可（見
// PortalController 開頭註解）。
//
// 刻意不提供 delete()：端口一旦有 Role／Menu／UserPortalAccess 掛著就很難
// 乾淨刪除，v1 只做建立與停用（is_enabled=false），跟 Dictionary「使用中
// 不能刪除」同一個保守精神，但更進一步直接不開放刪除，之後有具體需求
// （例如確認一個端口從未被使用過）再視情況擴充。
class PortalAdminService
{
    public function queryAll(): Builder
    {
        return Portal::query()->orderBy('id');
    }

    // portal_key 建立後不可變更（見 update()），多處程式碼與 seeder、前端
    // 各自的 SPA 建置（PORTAL_KEY 常數）都拿它當識別碼，格式驗證在
    // Controller 層（小寫英數底線，同 Menu 的 key 規則），這裡只檢查唯一性。
    public function create(User $actor, array $data, ?string $ip = null): Portal
    {
        if (Portal::query()->where('portal_key', $data['portal_key'])->exists()) {
            throw new RuntimeException(__('api.portal.key_exists', ['key' => $data['portal_key']]));
        }

        $portal = Portal::query()->create([
            'portal_key' => $data['portal_key'],
            'name' => $data['name'],
            'admin_only' => $data['admin_only'] ?? false,
            'is_enabled' => $data['is_enabled'] ?? true,
            'requires_login' => $data['requires_login'] ?? true,
        ]);

        $this->audit($actor, 'portal.create', $portal, $ip);

        return $portal;
    }

    // 只能改 name/admin_only/is_enabled/requires_login，portal_key 不接受
    // 更新（即使 $data 裡帶了也忽略，避免呼叫端誤用把識別碼改掉）。
    public function update(User $actor, Portal $portal, array $data, ?string $ip = null): Portal
    {
        $portal->update([
            'name' => $data['name'],
            'admin_only' => $data['admin_only'] ?? false,
            'is_enabled' => $data['is_enabled'] ?? true,
            'requires_login' => $data['requires_login'] ?? true,
        ]);

        $this->audit($actor, 'portal.update', $portal, $ip);

        return $portal;
    }

    private function audit(User $actor, string $action, Portal $portal, ?string $ip): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => "portal:{$portal->portal_key}",
            'context' => ['portal_id' => $portal->id],
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
