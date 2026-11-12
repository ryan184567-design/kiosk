<?php

/* *****************************************
File: 2024_01_01_000004_create_site_modules_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站模塊開通中介表，見 README 6.9 第 2 點。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 子站模塊開通中介表，見 README 6.9 第 2 點。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('module_code');
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'module_code']);
            $table->foreign('site_id')->references('site_id')->on('sites')->cascadeOnDelete();
            $table->foreign('module_code')->references('code')->on('modules')->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_modules');
    }
};
