<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// API 呼叫記錄補上更完整的資訊：路由樣式、請求起始時間（毫秒）、耗時、回應 header 與回應大小。
// 其餘請求細節（client／headers／query／form／json／files／raw_body）放在既有的 request_data（JSON）裡。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factory_logs', function (Blueprint $table) {
            $table->string('route', 255)->nullable()->after('path')->comment('路由樣式，如 /api/factory/guest/applies/{id}');
            $table->dateTime('started_at', 3)->nullable()->after('route')->comment('請求起始時間（毫秒精度）；created_at 是寫入記錄的時間');
            $table->unsignedInteger('duration_ms')->nullable()->after('started_at')->comment('處理耗時（毫秒）');
            $table->longText('response_headers')->nullable()->after('response_data')->comment('回應 header（JSON，敏感值已遮罩）');
            $table->unsignedInteger('response_size')->nullable()->after('response_headers')->comment('回應內容大小（bytes，截斷前）');
        });
    }

    public function down(): void
    {
        Schema::table('factory_logs', function (Blueprint $table) {
            $table->dropColumn(['route', 'started_at', 'duration_ms', 'response_headers', 'response_size']);
        });
    }
};
