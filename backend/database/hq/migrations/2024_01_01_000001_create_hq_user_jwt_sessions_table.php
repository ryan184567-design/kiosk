<?php

/* *****************************************
File: 2024_01_01_000001_create_hq_user_jwt_sessions_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站自己的 JWT session 表，獨立於子站的 user_jwt_sessions（見 README 6.12 第 2 點）， 因為根本是不同資料庫，不可混用。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 主站自己的 JWT session 表，獨立於子站的 user_jwt_sessions（見 README 6.12 第 2 點），
// 因為根本是不同資料庫，不可混用。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hq_user_jwt_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hq_user_id')->constrained('hq_users')->cascadeOnDelete();
            $table->string('jti')->unique();
            $table->timestamp('last_access_at')->nullable();
            $table->string('last_access_ip', 45)->nullable();
            $table->string('last_access_user_agent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hq_user_jwt_sessions');
    }
};
