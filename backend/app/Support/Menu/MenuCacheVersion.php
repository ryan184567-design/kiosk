<?php

/* *****************************************
File: MenuCacheVersion.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 7.3 第 3 點：子站開通/關閉模塊時需清除該站選單快取。
***************************************** */

namespace App\Support\Menu;

use Illuminate\Support\Facades\Cache;

// 見 README 7.3 第 3 點：子站開通/關閉模塊時需清除該站選單快取。不用
// Cache tags（file/database 驅動不支援），改用「版本號」做法：module 切換
// 時 bump 版本，快取 key 帶版本號，舊版本 key 靠自然過期回收，不需要
// 逐一列舉刪除。
class MenuCacheVersion
{
    public function current(int $siteId): int
    {
        return (int) Cache::get($this->key($siteId), 1);
    }

    public function bump(int $siteId): void
    {
        Cache::forever($this->key($siteId), $this->current($siteId) + 1);
    }

    private function key(int $siteId): string
    {
        return "menu_cache_version:site:{$siteId}";
    }
}
