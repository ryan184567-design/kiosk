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
        Schema::create('access_floor', function (Blueprint $table) {
            $table->id();
            $table->integer('floor')->default(1)->comment('樓層');
            $table->text('access_control_list')->comment('可通行裝置');
            $table->text('floor_list')->comment('可通行電梯與樓層');
            $table->string('remark', 200)->nullable()->comment('說明');
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
        Schema::dropIfExists('access_floor');
    }
};
