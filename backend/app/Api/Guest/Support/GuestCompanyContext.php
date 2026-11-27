<?php

/* *****************************************
File: GuestCompanyContext.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 目前請求的公司範圍（只有登入 company 端口時才有值），供 GuestApply 全域範圍與 Controller 讀取。
***************************************** */

namespace App\Api\Guest\Support;

use App\Api\Guest\Models\GuestCompanyMember;

// 值由 EnsureGuestCompanyScope 中介層放進 request attributes，生命週期跟著請求走：
// admin 端口、訪客機（factory）、CLI 都不會設定，回傳 null＝不限公司。
class GuestCompanyContext
{
    public const ATTRIBUTE = 'guest_company_member';

    public static function member(): ?GuestCompanyMember
    {
        if (! app()->bound('request')) {
            return null;
        }

        return request()->attributes->get(self::ATTRIBUTE);
    }

    public static function doorplateId(): ?int
    {
        return self::member()?->doorplate_id;
    }
}
