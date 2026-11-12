<?php

/* *****************************************
File: 2024_01_02_000007_create_site_plugin_configs_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站插件設定表，見 README 6.10.2。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 子站插件設定表，見 README 6.10.2。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_plugin_configs', function (Blueprint $table) {
            $table->id();
            $table->string('function_code')->comment('例如 ad、sso、access_control，見 6.10.1');
            $table->string('slot')->default('default');
            $table->string('brand_version_key')->comment('對應 config/plugins.php 的 key，例如 microsoft_v2019');
            $table->text('config')->nullable()->comment('加密儲存，見 encrypted:array cast，6.10.2');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['function_code', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_plugin_configs');
    }
};
