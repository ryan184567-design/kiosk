<?php

/* *****************************************
File: 2024_01_01_000002_create_sites_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站開站記錄表，存在主站自己的資料庫（見 README 6.2）。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 子站開站記錄表，存在主站自己的資料庫（見 README 6.2）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id('site_id');
            $table->string('code')->unique()->comment('小寫英數底線，見 6.3 建站流程驗證規則');
            $table->string('name');
            $table->string('primary_domain')->nullable();
            $table->string('path_prefix')->nullable();
            $table->string('db_connection')->default('site_current');
            $table->string('db_name')->comment('= SITE_DB_PREFIX 設定值 + code，見 6.3');
            $table->enum('site_type', ['standard', 'customized'])->default('standard');
            $table->string('template_namespace')->nullable()->comment('預設等於 code，見 9.2');
            $table->unsignedBigInteger('clone_from_site_id')->nullable();
            $table->enum('provision_status', ['pending', 'creating', 'ready', 'failed'])->default('pending');
            $table->string('provision_failed_reason')->nullable();
            $table->enum('vue_build_mode', ['shared', 'dedicated'])->default('shared')->comment('見 8.1');
            $table->timestamp('last_migrated_at')->nullable();
            $table->string('last_migration_batch')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('clone_from_site_id')->references('site_id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
