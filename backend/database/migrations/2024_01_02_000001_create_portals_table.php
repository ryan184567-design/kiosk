<?php

/* *****************************************
File: 2024_01_02_000001_create_portals_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 端口表，見 README 7.2、9.1。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 端口表，見 README 7.2、9.1。取代原本掛在身份別上的 portal_key。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portals', function (Blueprint $table) {
            $table->id();
            $table->string('portal_key')->unique()->comment('對應前端入口/端口，見 9.1');
            $table->string('name');
            $table->boolean('admin_only')->default(false)
                ->comment('true=僅端口管理員可登入，見 7.2 登入規則');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portals');
    }
};
