<?php

/* *****************************************
File: 2024_01_03_000000_add_soft_deletes_to_roles_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 開發約定第 8 點：除非特別說明，刪除 API 一律軟刪除。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 見 README 開發約定第 8 點：除非特別說明，刪除 API 一律軟刪除。角色是本
// 專案第一支真正的刪除 API（RoleController::destroy），補上 deleted_at。
//
// 同時移除 (portal_id, name) 的資料庫層唯一鍵：MySQL 沒有部分索引，唯一鍵
// 會連同已軟刪除的紀錄一起擋，導致「角色名稱刪除後就永遠不能重複使用」，
// 不是預期行為。改成在 RoleService 建立角色前用一般查詢檢查是否重複
// （Eloquent 的 SoftDeletes 全域 scope 本來就會排除已刪除的紀錄，語意正確）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->softDeletes();
            // portal_id 的 foreignId()->constrained() 這個 FK 約束原本是靠
            // 這個複合唯一鍵當支撐索引（MySQL 沒有另外建單欄位索引），直接
            // drop 唯一鍵會報 1553。先補一個單欄位索引讓 FK 有得依附，才能
            // 安全移除複合唯一鍵。
            $table->index('portal_id');
            $table->dropUnique(['portal_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['portal_id', 'name']);
            $table->dropIndex(['portal_id']);
            $table->dropSoftDeletes();
        });
    }
};
