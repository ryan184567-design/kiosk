<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('access_cards', function (Blueprint $table) {
            $table->string('card_number', 20)->comment('卡號')->change();
            $table->string('access_number', 20)->comment('編碼卡號')->change();
            $table->unique('card_number');
            $table->dropIndex(['card_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_cards', function (Blueprint $table) {
            $table->dropUnique(['card_number']);
            $table->integer('card_number')->comment('卡號')->change();
            $table->index('card_number');
        });
    }
};
