<?php

/* *****************************************
File: 2024_01_02_000010_add_username_to_users_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 登入改用帳號密碼，不使用 email（見對話紀錄）。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 登入改用帳號密碼，不使用 email（見對話紀錄）。email 保留但改為非必填的
// 聯絡資訊欄位，不再是登入識別欄位。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('identity_type_id');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
