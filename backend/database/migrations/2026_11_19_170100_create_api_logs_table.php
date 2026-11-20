<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 40)->nullable()->index();
            $table->string('type', 20)->index()->comment('token=簽發, scope_denied=權限被拒');
            $table->boolean('success')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('scope', 100)->nullable();
            $table->string('reason', 100)->nullable()->comment('失敗原因（僅供後台查看，不回給調用方）');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
