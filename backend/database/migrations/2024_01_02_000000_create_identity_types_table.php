<?php

/* *****************************************
File: 2024_01_02_000000_create_identity_types_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 身份別分類表，見 README 7.1。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 身份別分類表，見 README 7.1。每個子站各有一份，僅作分類標示，
// 不直接決定權限（權限判斷一律走 user_portal_access，見 7.2）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_system')->default(false)
                ->comment('超級管理員（我方隱藏身份）專用旗標，見 7.1，全站唯一一筆');
            $table->boolean('is_delegation_root')->default(false)
                ->comment('系統管理員專用旗標：授權他人時不受授權上限限制，見 7.1，全站唯一一筆');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_types');
    }
};
