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
        Schema::create('access_cards', function (Blueprint $table) {
            $table->id();
            $table->integer('card_number')->index()->comment('卡號');
            $table->integer('access_number')->comment('編碼卡號');
            $table->tinyInteger('status')->default(0)->comment('使用狀態');
            $table->string('person_id', 200)->nullable()->comment('訪客卡號');
            $table->date('reserve_date')->nullable()->comment('啟用日期');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_cards');
    }
};
