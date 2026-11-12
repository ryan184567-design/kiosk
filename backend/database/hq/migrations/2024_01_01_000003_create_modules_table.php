<?php

/* *****************************************
File: 2024_01_01_000003_create_modules_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 模塊登記清單，見 README 6.9。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 模塊登記清單，見 README 6.9。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('對應目錄名稱 app/api/{module}');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false)->comment('核心模塊恆為開通，不進可勾選清單');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true)->comment('false=對所有子站全域下架');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
