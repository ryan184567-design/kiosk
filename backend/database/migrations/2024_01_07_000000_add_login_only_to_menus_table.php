<?php

/* *****************************************
File: 2024_01_07_000000_add_login_only_to_menus_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 選單新增「僅需登入即可見」旗標，見 README 7.3。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 選單新增「僅需登入即可見」旗標，見 README 7.3。給像 Dashboard 這種
// 任何登入者都該看到、不綁特定權限碼的頂層選單使用；預設 false 不影響
// 既有選單的既有可見性判斷（module_code／permission_code 那套邏輯）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('login_only')->default(false)->after('permission_code')->comment('true=只要登入即可見，不檢查 module_code/permission_code');
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn('login_only');
        });
    }
};
