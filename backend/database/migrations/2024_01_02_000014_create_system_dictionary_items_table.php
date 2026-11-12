<?php

/* *****************************************
File: 2024_01_02_000014_create_system_dictionary_items_table.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典（項目），見 README 17.3；同一字典底下的 value 是實際被 system_configs.cfg_value 等處引用的值。
***************************************** */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 資料字典（項目），見 README 17.3。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained('system_dictionaries')->cascadeOnDelete();
            $table->string('value')->comment('實際被引用的值，例如存進 system_configs.cfg_value 的內容');
            $table->string('label')->comment('顯示文字');
            $table->boolean('is_enabled')->default(true);
            $table->tinyInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['dictionary_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_dictionary_items');
    }
};
