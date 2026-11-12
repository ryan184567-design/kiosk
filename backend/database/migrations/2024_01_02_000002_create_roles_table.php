<?php

/* *****************************************
File: 2024_01_02_000002_create_roles_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色表，見 README 7.2、7.4。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 角色表，見 README 7.2、7.4。角色歸屬於端口，不可跨端口共用。
//
// 骨架階段簡化：permission_codes 直接存在角色上（JSON 陣列，支援
// api.{module}.* 這種萬用字元字串，比對邏輯見 PermissionService），
// 暫不建立獨立的 permissions / permission_role 正規化表與
// permission:sync-api 掃描機制（見 README 7.4），留待後續依實際模塊
// 數量決定是否需要正規化。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained('portals')->cascadeOnDelete();
            $table->string('name');
            $table->json('permission_codes')->nullable()
                ->comment('例如 ["api.order.*", "web.order.view"]，見 7.4');
            $table->timestamps();

            $table->unique(['portal_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
