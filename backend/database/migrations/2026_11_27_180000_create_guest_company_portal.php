<?php

/* *****************************************
File: 2026_11_27_180000_create_guest_company_portal.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 建立訪客模組公司端口（company）與三個固定角色；只補缺少的資料，不覆寫既有設定。
***************************************** */

use App\Api\Guest\Models\GuestCompanyMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 為什麼用 migration 建資料：訪客 API 的 @api-portal 標了 company，資料庫沒有這個端口時
// permission:sync-api／swagger:generate 會直接中斷（「@api-portal 標註了不存在的端口：company」）。
// 端口原本只在 SiteBaselineSeeder 建立，但 seeder 不會自動套用到既有子站，拉更新後只跑
// site:migrate 的人一定會踩到，所以改由 migration 保證端口與角色存在。
// 只補缺少的，不覆寫既有資料（端口名稱、啟用狀態、角色權限碼都可能已經被後台或 seeder 調整過）；
// 角色權限碼要同步成最新版本，仍是重跑 baseline seed（見 SiteBaselineSeeder::seedGuestCompanyPortal）。
return new class extends Migration
{
    public function up(): void
    {
        $portalId = DB::table('portals')->where('portal_key', 'company')->value('id');

        if (! $portalId) {
            $portalId = DB::table('portals')->insertGetId([
                'portal_key' => 'company',
                'name' => '公司端口',
                'admin_only' => false,
                'requires_login' => true,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 單站部署包沒帶訪客模組原始碼時（未購買）只建端口、不建角色，與 seeder 的處理一致。
        if (! class_exists(GuestCompanyMember::class)) {
            return;
        }

        foreach (GuestCompanyMember::rolePermissionCodes() as $type => $codes) {
            $name = GuestCompanyMember::ROLE_NAMES[$type];
            $role = DB::table('roles')->where('portal_id', $portalId)->where('name', $name)->first();

            if (! $role) {
                DB::table('roles')->insert([
                    'portal_id' => $portalId,
                    'name' => $name,
                    'permission_codes' => json_encode($codes),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($role->deleted_at !== null) {
                // 被軟刪除的固定角色：復原並套用目前的權限碼（帳號依名稱指派角色，不能缺）。
                DB::table('roles')->where('id', $role->id)->update([
                    'permission_codes' => json_encode($codes),
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    // 不刪除：端口與角色一旦被使用，刪除會連帶清掉 user_portal_access（外鍵 cascade），
    // 讓公司帳號全部失去登入權限，rollback 不應該造成這種資料遺失。
    public function down(): void
    {
    }
};
