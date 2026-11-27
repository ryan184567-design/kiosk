<?php

/* *****************************************
File: 2026_11_27_150000_add_guest_company_portal_tables.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 訪客模組公司端口：門牌（公司）加審核開關、預約單記錄建立者、公司成員表。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 一個門牌＝一家公司：審核開關直接放在門牌上（預設需要審核，維持既有行為）。
        Schema::table('doorplates', function (Blueprint $table) {
            $table->boolean('approval_required')->default(true)->after('company_name')->comment('員工新增預約是否需要審核');
        });

        Schema::table('guest_applies', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->index()->after('reservation_status')->comment('建立者 users.id（訪客機建立為 null）');
        });

        // 使用者隸屬哪家公司（門牌）與公司內身分；一個使用者只屬於一家公司（由程式保證，
        // 不加唯一索引，避免軟刪除後同一帳號無法重新加入）。
        Schema::create('guest_company_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index()->comment('users.id');
            $table->unsignedBigInteger('doorplate_id')->index()->comment('所屬公司（門牌）ID');
            $table->tinyInteger('member_type')->default(3)->comment('公司內身分(1:公司管理員、2:審核者、3:一般員工)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_company_members');

        Schema::table('guest_applies', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });

        Schema::table('doorplates', function (Blueprint $table) {
            $table->dropColumn('approval_required');
        });
    }
};
