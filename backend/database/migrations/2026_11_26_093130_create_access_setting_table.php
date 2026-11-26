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
        Schema::create('access_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 500)->nullable()->comment('api名稱');
            $table->string('url', 500)->nullable()->comment('API 網址');
            $table->string('type', 20)->default('api')->comment('路徑類型');
            $table->tinyInteger('status')->default(1)->comment('使用狀態');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_settings');
    }
};
