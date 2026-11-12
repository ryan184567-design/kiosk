<?php

/* *****************************************
File: 2024_01_04_000000_add_soft_deletes_to_menus_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 開發約定第 8 點：除非特別說明，刪除 API 一律軟刪除。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 見 README 開發約定第 8 點：除非特別說明，刪除 API 一律軟刪除。選單管理
// （7.3）新增 CRUD API，補上 deleted_at；子選單存在時拒絕刪除父節點
// （見 MenuAdminService::delete），不依賴資料庫層 cascade。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
