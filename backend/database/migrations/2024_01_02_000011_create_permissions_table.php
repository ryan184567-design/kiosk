<?php

/* *****************************************
File: 2024_01_02_000011_create_permissions_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 權限目錄，見 README 7.4、12.4、18.2.1。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 權限目錄，見 README 7.4、12.4、18.2.1。由 permission:sync-api/sync-web 掃描
// Controller 註解產生，供角色管理介面挑選權限碼（角色實際授予的權限存在
// roles.permission_codes，見 7.2，這張表只是「有哪些權限碼存在」的目錄）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('例如 api.order.view，見 7.4');
            $table->string('module_code');
            $table->enum('domain', ['api', 'web'])->comment('對應 permission.code 前綴，見 7.4');
            $table->string('method')->nullable();
            $table->string('path')->nullable();
            $table->json('portal_keys')->nullable()->comment('@api-portal 標註，見 12.4');
            $table->string('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
