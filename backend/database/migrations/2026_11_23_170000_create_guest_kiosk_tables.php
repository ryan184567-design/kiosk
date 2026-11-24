<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 訪客機狀態：
     * - guest_kiosk_statuses：每台訪客機一列，只記「最後回報時間」與「目前未恢復的故障」；
     *   給儀錶板的心跳每分鐘查這張表，超過 1 分鐘沒回報就視為訪客機離線／故障。
     * - guest_kiosk_logs：訪客機每一次回報（心跳、故障）的日誌，排查用。
     */
    public function up(): void
    {
        Schema::create('guest_kiosk_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 64)->unique()->comment('訪客機的 App ID（factory_apps.app_id）');
            $table->dateTime('last_reported_at')->nullable()->comment('最後一次回報時間（心跳或故障回報都算）');
            $table->json('faults')->nullable()->comment('目前未恢復的故障 [{fault_code, occurred_at}]，沒有故障為空陣列');
            $table->timestamps();
        });

        Schema::create('guest_kiosk_logs', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 64)->comment('訪客機的 App ID');
            $table->string('log_type', 20)->comment('回報類型: heartbeat 心跳, fault 故障／恢復／系統正常');
            $table->string('fault_code', 40)->nullable()->comment('故障代碼（log_type=fault 才有）');
            $table->string('fault_status', 20)->nullable()->comment('occurred 發生, resolved 已恢復（log_type=fault 才有）');
            $table->json('payload')->nullable()->comment('訪客機傳來的內容');
            $table->string('ip', 45)->nullable()->comment('來源 IP');
            $table->timestamp('created_at')->nullable()->comment('回報時間');

            $table->index(['app_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_kiosk_logs');
        Schema::dropIfExists('guest_kiosk_statuses');
    }
};
