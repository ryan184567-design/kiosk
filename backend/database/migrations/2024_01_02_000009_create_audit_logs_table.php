<?php

/* *****************************************
File: 2024_01_02_000009_create_audit_logs_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站自己的審計日誌，見 README 15 節：跟著各自資料庫走，不是集中表。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 子站自己的審計日誌，見 README 15 節：跟著各自資料庫走，不是集中表。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('target')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
