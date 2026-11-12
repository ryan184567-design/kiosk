<?php

/* *****************************************
File: 2024_01_02_000013_create_system_dictionaries_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典（類型），見 README 17.3；system_configs.cfg_type=dic 依 cfg_option 存的代碼查這張表。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 資料字典（類型），見 README 17.3。存在各站點自己的資料庫，site_id 不用於跨站區分
// （比照 system_configs 的既有慣例，見 17）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_dictionaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(0)
                ->comment('固定為目前所屬站點的識別碼，比照 system_configs 慣例');
            $table->string('code', 100)->comment('字典代碼，供 system_configs.cfg_option（cfg_type=dic）查詢');
            $table->string('name')->comment('顯示名稱');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->tinyInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_dictionaries');
    }
};
