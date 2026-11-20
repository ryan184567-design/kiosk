<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 廠商模式（App Token）除錯模式：
// - factory_apps.is_debug：除錯模式開啟後，這個 App 的每次呼叫（成功失敗都）記錄；關閉時只記錄非 2xx 的呼叫。
// - factory_logs 增加呼叫細節欄位：方法、路徑、狀態碼、傳入參數、完整回應（type = 'call'）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factory_apps', function (Blueprint $table) {
            $table->boolean('is_debug')->default(false)->after('status')->comment('除錯模式：1=每次呼叫都記錄, 0=只記錄非 2xx');
        });

        // 後台日誌要能依「App ID、業務模塊、具體方法、結果、時間」搜尋，所以這幾欄都建索引。
        Schema::table('factory_logs', function (Blueprint $table) {
            $table->string('module', 50)->nullable()->after('scope')->index()->comment('被呼叫的業務模塊代碼，如 guest');
            $table->string('action', 150)->nullable()->after('module')->index()->comment('具體方法：Controller@method，如 FactoryGuestApplyController@index');
            $table->string('method', 10)->nullable()->after('action')->comment('HTTP 方法');
            $table->string('path', 255)->nullable()->after('method');
            $table->unsignedSmallInteger('status_code')->nullable()->after('path')->index();
            $table->longText('request_data')->nullable()->after('status_code')->comment('傳入參數（JSON，敏感欄位已遮罩）');
            $table->longText('response_data')->nullable()->after('request_data')->comment('完整回應（敏感欄位已遮罩，過長截斷）');
            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('factory_logs', function (Blueprint $table) {
            $table->dropIndex(['app_id', 'created_at']);
            $table->dropColumn(['module', 'action', 'method', 'path', 'status_code', 'request_data', 'response_data']);
        });

        Schema::table('factory_apps', function (Blueprint $table) {
            $table->dropColumn('is_debug');
        });
    }
};
