<?php

/* *****************************************
File: DatabaseSeeder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站 baseline seed 進入點，實際內容見 SiteBaselineSeeder，見 README 13 節。
***************************************** */

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SiteBaselineSeeder::class);
    }
}
