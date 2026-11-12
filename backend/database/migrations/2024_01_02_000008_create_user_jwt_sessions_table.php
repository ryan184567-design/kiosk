<?php

/* *****************************************
File: 2024_01_02_000008_create_user_jwt_sessions_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: JWT 會話表，見 README 11.1。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// JWT 會話表，見 README 11.1。單帳號單 Token 的限制範疇是「同一帳號在同一端口」，
// 不是整個帳號，因此以 (user_id, portal_id) 決定目前有效 Token。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_jwt_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portal_id')->constrained('portals')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('jti')->unique();
            $table->string('issued_via')->default('login')
                ->comment('login 或 hq_impersonation，見 6.11');
            $table->timestamp('last_access_at')->nullable();
            $table->string('last_access_ip', 45)->nullable();
            $table->string('last_access_user_agent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'portal_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_jwt_sessions');
    }
};
