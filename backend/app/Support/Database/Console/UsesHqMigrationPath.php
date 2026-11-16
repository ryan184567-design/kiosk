<?php

/* *****************************************
File: UsesHqMigrationPath.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-16
Description: 多站模式下，裸跑 migrate 系列指令時自動改用主站 migration 路徑（防呆）。
***************************************** */

namespace App\Support\Database\Console;

// 防呆：本專案 `database/migrations` 是「子站」schema，主站 schema 在
// `database/hq/migrations`。若裸跑 `php artisan migrate`，Laravel 預設會把子站表建到
// 主站資料庫。這裡讓 migrate／migrate:status／migrate:rollback／migrate:reset
// （migrate:fresh、migrate:refresh 內部會呼叫它們，一併生效）在「多站模式、預設連線、
// 沒指定 --path」時自動改用主站路徑。
//
// 只要明確帶了 --path 或 --database（例如 site:migrate、建站流程內部的呼叫），
// 就完全照原本行為，不介入。單站模式（MULTI_SITE_ENABLED=false）沒有主站，
// 維持 Laravel 預設（database/migrations）。
trait UsesHqMigrationPath
{
    protected function getMigrationPaths()
    {
        if ($this->shouldUseHqMigrationPath()) {
            return [$this->laravel->basePath('database/hq/migrations')];
        }

        return parent::getMigrationPaths();
    }

    private function shouldUseHqMigrationPath(): bool
    {
        return (bool) config('multisite.enabled')
            && ! $this->option('path')
            && ! $this->option('database');
    }
}
