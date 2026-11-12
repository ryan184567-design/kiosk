<?php

/* *****************************************
File: 2024_01_01_000006_create_cache_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Laravel 框架自帶表：hq 資料庫也需要一份，因為 CACHE_STORE=database 是 用預設連線（見 .env），不是子站 site_current 連線。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel 框架自帶表：hq 資料庫也需要一份，因為 CACHE_STORE=database 是
// 用預設連線（見 .env），不是子站 site_current 連線。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
