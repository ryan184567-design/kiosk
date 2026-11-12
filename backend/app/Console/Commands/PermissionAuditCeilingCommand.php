<?php

/* *****************************************
File: PermissionAuditCeilingCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 稽核既有角色授權紀錄是否逾越 7.1「授權上限」規則，見 README 7.4、18.2 第 7-9 項。
***************************************** */

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SelectsSite;
use App\Support\Permission\Models\Permission;
use App\Api\Role\Models\Role;
use Illuminate\Console\Command;

// 稽核既有角色授權紀錄是否逾越 7.1「授權上限」規則，見 README 7.4、18.2 第 7-9 項。
//
// 誠實說明本指令實際能稽核到什麼程度：本框架目前的資料模型（roles.permission_codes
// 是純 JSON 陣列，見 7.4 設計說明）沒有紀錄「這筆授權是誰在什麼時候給的」，只有
// is_system/is_delegation_root 兩種身份的操作不受上限限制（見 7.1），其餘授權管道
// （端口管理員在自己端口內授權）本來就不可能超過自己持有的端口全部權限，邏輯上
// 不會違反上限。所以無法做到「回溯每一筆授權當下，授權者是否真的持有該權限」這種
// 完整稽核（需要額外的授權歷程記錄表才能做，目前沒有）。
//
// 這裡做的是資料模型上真正能查得出來、也確實有意義的檢查：
//   1. 角色的權限碼是否對應到 permissions 目錄裡真實存在的項目（抓打字錯誤、
//      抓模塊/權限已移除但角色還留著舊碼）。
//   2. 若該權限碼有標註允許的端口（@api-portal，見 12.4），角色所屬的端口
//      是否在允許清單內（抓「權限碼寫對了但端口不對」這種跨端口洩漏）。
class PermissionAuditCeilingCommand extends Command
{
    use SelectsSite;

    protected $signature = 'permission:audit-ceiling {--site= : 要稽核哪個子站，見 --site={site_id}}';

    protected $description = '稽核角色權限碼是否對應有效目錄、有無跨端口洩漏（見 README 7.4、7.1）';

    public function handle(): int
    {
        if (! $this->resolveSiteOrFail()) {
            return self::FAILURE;
        }

        $catalog = Permission::all();
        $issues = 0;

        foreach (Role::with('portal')->get() as $role) {
            foreach ($role->permission_codes ?? [] as $code) {
                $matches = $catalog->filter(fn (Permission $p) => $this->matches($code, $p->code));

                if ($matches->isEmpty()) {
                    $this->warn("[角色「{$role->name}」/端口「{$role->portal?->portal_key}」] 權限碼 {$code} 找不到對應目錄項目（可能是打字錯誤或模塊已移除）。");
                    $issues++;

                    continue;
                }

                $crossPortal = $matches->filter(function (Permission $p) use ($role) {
                    $allowed = $p->portal_keys ?? [];

                    // 見 12.4：@api-portal * 代表刻意不限端口，portal_keys 會
                    // 原封不動存成 ['*']，不是「只允許字面上叫 * 的端口」，
                    // 這裡要跟 ApiAnnotationValidator 的語意一致，否則每一支
                    // 標了 * 的 API 都會被誤判成跨端口洩漏。
                    if (in_array('*', $allowed, true)) {
                        return false;
                    }

                    return $allowed && ! in_array($role->portal?->portal_key, $allowed, true);
                });

                if ($crossPortal->count() === $matches->count()) {
                    $this->warn("[角色「{$role->name}」/端口「{$role->portal?->portal_key}」] 權限碼 {$code} 標註的允許端口不含此角色所屬端口，可能造成跨端口權限外洩。");
                    $issues++;
                }
            }
        }

        if ($issues === 0) {
            $this->info('未發現問題。');
        } else {
            $this->error("共發現 {$issues} 筆疑似問題，見上方列表。");
        }

        return self::SUCCESS;
    }

    private function matches(string $roleCode, string $catalogCode): bool
    {
        if ($roleCode === $catalogCode) {
            return true;
        }

        if (str_ends_with($roleCode, '.*')) {
            return str_starts_with($catalogCode, substr($roleCode, 0, -1));
        }

        return false;
    }
}
