<?php

/* *****************************************
File: RoleService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 9.2：驗證 SiteOverrideResolver 機制是否正常運作的模擬客製化 範例，非真實業務需求——vendora 站建立角色時一律加上 "[VendorA] " 前綴， 方便直接從 API 回傳資料肉眼確認目前解析到的是這支覆寫類別、不是公版的 App\Api\Role\Services\RoleService。
***************************************** */

namespace App\Sites\Vendora\Api\Role\Services;

use App\Api\Role\Services\RoleService as SharedRoleService;
use App\Api\Role\Models\Role;
use App\Api\User\Models\User;

// 見 README 9.2：驗證 SiteOverrideResolver 機制是否正常運作的模擬客製化
// 範例，非真實業務需求——vendora 站建立角色時一律加上 "[VendorA] " 前綴，
// 方便直接從 API 回傳資料肉眼確認目前解析到的是這支覆寫類別、不是公版的
// App\Api\Role\Services\RoleService。驗證完成後可以刪除，不影響公版行為
// （其他站點沒有 app/Sites/{SiteCode} 目錄，SiteOverrideResolver 完全不會
// 動到）。
class RoleService extends SharedRoleService
{
    public function create(User $granter, int $portalId, string $name, array $permissionCodes, ?string $ip = null): Role
    {
        return parent::create($granter, $portalId, "[VendorA] {$name}", $permissionCodes, $ip);
    }
}
