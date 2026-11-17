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
            $table->string('applies_no', 50)->unique()->comment('預約單號');
            $table->unsignedBigInteger('doorplate_id')->comment('門牌ID（受訪公司）');
            $table->date('date')->comment('預約日期');
            $table->time('time')->comment('預約時段，格式 HH:mm~HH:mm，例如 10:00~11:00');
            $table->integer('visitor_count')->default(1)->comment('訪客人數');
            $table->string('host_name', 100)->comment('受訪人');
            $table->text('purpose')->nullable()->comment('來訪事由');
            $table->text('notes')->nullable()->comment('備註');

            // 通知與提醒開關
            $table->boolean('email_notify')->default(false)->comment('email通知');
            $table->boolean('sms_notify')->default(false)->comment('簡訊通知');
            $table->boolean('reminder_enabled')->default(false)->comment('到訪前提醒');
            $table->integer('reminder_time')->nullable()->comment('到訪前提醒時間(分鐘)');

            // 報到與審核資訊
            $table->string('qr_code', 255)->nullable()->index()->comment('QRcode驗證碼');
            $table->string('visit_code', 255)->nullable()->index()->comment('速通碼');
            $table->tinyInteger('booking_type')->default(1)->comment('預約類型: 1=事前預約, 2=現場預約');
            $table->timestamp('check_in_at')->nullable()->comment('到場時間');
            $table->timestamp('check_out_at')->nullable()->comment('離場時間');
            $table->tinyInteger('audit_status')->default(0)->comment('審核狀態: 0=待審核, 1=核准, 2=駁回, 3=已取消');

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
