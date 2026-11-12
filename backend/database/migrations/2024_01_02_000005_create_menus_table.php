<?php

/* *****************************************
File: 2024_01_02_000005_create_menus_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 選單表，見 README 7.3。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 選單表，見 README 7.3。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('portal_key');
            $table->string('module_code')->nullable()->comment('供 6.9 模塊開通判斷用，系統層選單可留空');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('key');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('icon')->nullable();
            $table->string('path')->nullable();
            $table->string('permission_code')->nullable()
                ->comment('沒有此欄位的葉節點一律不顯示，見 7.3');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('menus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
