<?php

/* *****************************************
File: 2024_01_02_000003_add_identity_type_id_to_users_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 7.1：一個帳號僅屬於一種身份別（單一外鍵，非多對多）。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 見 README 7.1：一個帳號僅屬於一種身份別（單一外鍵，非多對多）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('identity_type_id')->nullable()
                ->after('id')
                ->constrained('identity_types')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_type_id');
        });
    }
};
