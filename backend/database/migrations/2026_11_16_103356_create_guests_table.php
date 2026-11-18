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
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('guest_applies_id')->comment('預約單主表ID');
            $table->string('name', 100)->comment('訪客名字');
            $table->string('company_name', 150)->nullable()->comment('公司名');
            $table->string('title', 100)->nullable()->comment('職稱');
            $table->string('phone', 30)->comment('手機號碼');
            $table->string('email', 150)->nullable()->comment('email');
            $table->string('plate_number', 20)->nullable()->comment('車牌號碼');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
