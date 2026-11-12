<?php

/* *****************************************
File: 2024_01_02_000006_create_system_configs_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 系統環境配置表，見 README 17。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 系統環境配置表，見 README 17。存在各站點自己的資料庫，site_id 不用於跨站區分。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->default(0)
                ->comment('固定為目前所屬站點的識別碼，見 17.1');
            $table->string('cfg_name', 100);
            $table->string('cfg_title');
            $table->enum('cfg_type', ['string', 'int', 'enum', 'dic', 'date', 'datetime']);
            $table->json('cfg_option')->nullable();
            $table->json('cfg_style')->nullable();
            $table->text('cfg_value')->nullable();
            $table->text('cfg_default')->nullable();
            $table->string('cfg_module', 100);
            $table->tinyInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['cfg_module', 'cfg_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configs');
    }
};
