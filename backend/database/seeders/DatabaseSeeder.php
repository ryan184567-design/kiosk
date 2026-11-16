<?php

/* *****************************************
File: DatabaseSeeder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 裸跑 db:seed 的進入點：依運作模式選擇主站或子站 baseline seed。
***************************************** */

namespace Database\Seeders;

use Database\Hq\Seeders\HqDatabaseSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * 防呆：裸跑 `php artisan db:seed`（含 `migrate:fresh --seed`）時，
     *  - 多站模式、預設連線（沒帶 --database）：目標是主站資料庫 → 跑主站 seed。
     *  - 其餘（帶 --database=site_current 的子站流程，或單站模式）→ 跑子站 baseline seed。
     * 與 migrate 系列的防呆規則一致，見 App\Support\Database\Console\UsesHqMigrationPath。
     */
    public function run(): void
    {
        $targetsHq = config('multisite.enabled') && ! $this->command?->option('database');

        $this->call($targetsHq ? HqDatabaseSeeder::class : SiteBaselineSeeder::class);
    }
}
