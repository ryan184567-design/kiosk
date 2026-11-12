<?php

/* *****************************************
File: 2024_01_01_000000_create_hq_users_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站自身的使用者表，見 README 6.12。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 主站自身的使用者表，見 README 6.12。範疇很窄（只給我方維運/工程團隊用），
// 刻意不套用子站那套 identity_types/portals/roles 的完整模型。
//
// 連線注意：這份 migration 刻意不寫死連線名稱，交由執行指令的 --database=
// 決定（hq 遷移預設用 .env 的預設連線，見 README 6.1）；子站 migration
// （database/migrations/）同理，不可以在檔案內寫死連線，否則會蓋錯資料庫。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hq_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_super_admin')->default(false)
                ->comment('true=可執行高風險操作（site:create、跳轉登入、模塊全域下架）；false=僅能查看');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_users');
    }
};
