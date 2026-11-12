<?php

/* *****************************************
File: 2024_01_06_000000_add_target_to_menus_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單新增開啟方式（目前視窗／新視窗），見 README 7.3。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 選單新增開啟方式（目前視窗／新視窗），見 README 7.3。用字串而非資料庫
// enum（同 2024_01_05_000000 的理由：往後若要加其他開啟方式不用改表結構），
// 合法值在應用層驗證（App\Api\Menu\Controllers\MenuController）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('target', 10)->default('_self')->after('path')->comment('_self=目前視窗，_blank=新視窗');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('target');
        });
    }
};
