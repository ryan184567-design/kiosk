<?php

/* *****************************************
File: 2024_01_01_000008_create_sessions_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Laravel 框架自帶表：SESSION_DRIVER=database 用預設連線（見 .env）， 全域 web middleware 群組（見 bootstrap/app.php）即使是 hq 的路由也可能觸發。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel 框架自帶表：SESSION_DRIVER=database 用預設連線（見 .env），
// 全域 web middleware 群組（見 bootstrap/app.php）即使是 hq 的路由也可能觸發。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
