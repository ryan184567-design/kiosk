<?php

/* *****************************************
File: 2024_01_02_000004_create_user_portal_access_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 端口存取中介表，見 README 7.2。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 端口存取中介表，見 README 7.2。一人可跨多端口，各端口權限獨立。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_portal_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portal_id')->constrained('portals')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete()
                ->comment('is_portal_admin=true 時可為 null，見 7.2');
            $table->boolean('is_portal_admin')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'portal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_portal_access');
    }
};
