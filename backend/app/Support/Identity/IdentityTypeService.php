<?php

/* *****************************************
File: IdentityTypeService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 7.1 第 4 點：超級管理員的隱藏範圍集中在這裡，各 Controller/Service 統一呼叫，不各自寫 if 判斷。
***************************************** */

namespace App\Support\Identity;

use App\Support\Identity\Models\IdentityType;
use App\Api\User\Models\User;
use Illuminate\Database\Eloquent\Collection;

// 見 README 7.1 第 4 點：超級管理員的隱藏範圍集中在這裡，各 Controller/Service
// 統一呼叫，不各自寫 if 判斷。
class IdentityTypeService
{
    public function visibleTo(?User $viewer): Collection
    {
        return IdentityType::query()->visibleTo($viewer)->orderBy('sort')->get();
    }

    public function isSuperAdmin(?User $user): bool
    {
        return (bool) $user?->identityType?->is_system;
    }

    public function isDelegationRoot(?User $user): bool
    {
        return (bool) $user?->identityType?->is_delegation_root;
    }
}
