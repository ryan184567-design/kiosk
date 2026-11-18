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
        Schema::create('guest_applies', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id', 50)->unique()->comment('預約單號');
            $table->tinyInteger('reservation_type')->default(1)->comment('預約類型: 0:事前預約, 1:現場預約');

            $table->unsignedBigInteger('doorplate_id')->comment('門牌ID（受訪公司）');
            $table->string('host_name', 100)->comment('受訪人');

            $table->date('visit_date')->comment('預約日期');
            $table->time('visit_start_time')->comment('預約時段(起)');
            $table->time('visit_end_time')->comment('預約時段(迄)');
            $table->integer('guest_count')->default(1)->comment('訪客人數');
            $table->text('visit_reason')->nullable()->comment('來訪事由');
            $table->text('remark')->nullable()->comment('備註');

            // 通知與提醒開關
            $table->boolean('is_email_notify')->default(false)->comment('email通知');
            $table->boolean('is_sms_notify')->default(false)->comment('簡訊通知');
            $table->boolean('is_auto_remind')->default(false)->comment('到訪前提醒');
            $table->integer('remind_time')->nullable()->comment('到訪前提醒時間(分鐘)');

            // 報到與審核資訊
            $table->string('qr_code', 255)->nullable()->index()->comment('QRcode驗證碼');
            $table->string('fast_pass_code', 255)->nullable()->index()->comment('速通碼');
            $table->string('access_card_no', 255)->nullable()->index()->comment('門禁卡號');

            $table->timestamp('check_in_at')->nullable()->comment('到場時間');
            $table->timestamp('check_out_at')->nullable()->comment('離場時間');
            $table->tinyInteger('reservation_status')->default(0)->comment('預約單狀態(0:待審核、1:修改待審核、10:已核准、11:修改已核准、20:已通知、30:未到、40:已到、50:已離場、60:已取消)');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_applies');
    }
};
