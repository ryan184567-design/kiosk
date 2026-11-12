<?php

/* *****************************************
File: 2024_01_08_000000_add_requires_login_to_portals_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 端口新增「是否需要登入才能進入」旗標，見 README 9.1。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 端口新增「是否需要登入才能進入」旗標，見 README 9.1。預設 true：現有
// 端口（admin）行為完全不變，只有之後透過端口管理頁面手動關閉，該端口的
// SPA 才會允許訪客不登入直接瀏覽（見 GET /api/portals/public/{portal_key}
// 與前端 router guard）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portals', function (Blueprint $table) {
            $table->boolean('requires_login')->default(true)->after('admin_only')->comment('false=允許訪客不登入直接瀏覽此端口的 SPA');
        });
    }

    public function down(): void
    {
        Schema::table('portals', function (Blueprint $table) {
            $table->dropColumn('requires_login');
        });
    }
};
