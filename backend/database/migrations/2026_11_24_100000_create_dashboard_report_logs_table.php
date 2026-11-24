<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 儀錶板上報記錄：每一次實際送出（含重試的每一次、連線測試）各一列，記下送出的內容與對方的回應。
     * 不記標頭（簽名在標頭裡）；放在 POST 內容的簽名／固定資料值會遮罩。
     */
    public function up(): void
    {
        Schema::create('dashboard_report_logs', function (Blueprint $table) {
            $table->id();
            $table->string('notify_type', 40)->comment('通知類型: heartbeat, device_fault, reservation_*');
            $table->string('message_id', 64)->nullable()->comment('通知識別碼，同一則通知的重試共用');
            $table->unsignedTinyInteger('attempt')->default(1)->comment('第幾次嘗試（連線測試固定 1）');
            $table->boolean('is_test')->default(false)->comment('是否為連線測試');
            $table->string('url', 500)->comment('送出的網址');
            $table->boolean('success')->default(false)->comment('對方是否回 2xx');
            $table->unsignedSmallInteger('status_code')->nullable()->comment('對方回應的 HTTP 狀態碼，連不上或逾時為空');
            $table->unsignedInteger('duration_ms')->default(0)->comment('花費毫秒');
            $table->json('request_body')->nullable()->comment('送出的 POST 內容（簽名值已遮罩）');
            $table->text('response_body')->nullable()->comment('對方回應的內容（最多保留 5000 字）');
            $table->text('error')->nullable()->comment('失敗原因');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('message_id');
            $table->index(['notify_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_report_logs');
    }
};
