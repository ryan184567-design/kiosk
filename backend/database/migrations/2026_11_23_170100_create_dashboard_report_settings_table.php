<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 儀錶板上報設定：整張表只有一列（每個子站一組設定），沒有資料時由 DashboardReportService::settings() 自動建立預設值。
     */
    public function up(): void
    {
        Schema::create('dashboard_report_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false)->comment('是否啟用上報');
            $table->string('url', 500)->nullable()->comment('儀錶板 API 網址');
            $table->string('upload_method', 20)->default('api')->comment('上傳方式: api（目前僅支援）');
            $table->string('format', 20)->default('json')->comment('上傳格式: json（目前僅支援）');
            $table->json('contents')->nullable()->comment('上傳內容: heartbeat 心跳, approved 核准, modified 修改, cancelled 取消, checkin 報到, checkout 離場');
            $table->string('schedule', 20)->default('realtime')->comment('上傳排程: realtime 即時上傳（目前僅支援）');
            $table->text('auth_items')->nullable()->comment('自訂簽名／固定資料，可多組（加密儲存的 JSON）: [{name, location: header|body, value, bearer}]，介面只顯示遮罩');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_report_settings');
    }
};
