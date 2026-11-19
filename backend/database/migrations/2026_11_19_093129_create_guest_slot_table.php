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
        Schema::create('guest_slots', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('證件櫃名稱');
            $table->text('remark')->nullable()->comment('備註');
            $table->boolean('status')->default(1)->comment('使用狀態(0:未使用、1:使用中)');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_slots');
    }
};
