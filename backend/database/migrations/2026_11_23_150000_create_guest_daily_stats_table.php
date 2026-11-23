<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 訪客每日統計緩存表：每天凌晨 00:05 由 guest:stats-aggregate 彙整前一日資料。
     * 粒度＝日期 × 樓層 × 公司（門牌）× 來訪事由 × 預約類型，查一個月或一年都只需對這張表 groupBy，
     * 不必每次掃描 guest_applies；統計報表的樓層／公司／事由篩選也都能直接走這張表。
     */
    public function up(): void
    {
        Schema::create('guest_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date')->comment('統計日期（預約日期 visit_date）');
            $table->integer('floor')->default(0)->comment('受訪公司所在樓層，門牌不存在為 0');
            $table->unsignedBigInteger('doorplate_id')->default(0)->comment('受訪公司（門牌ID），門牌不存在為 0');
            $table->integer('visit_reason')->default(0)->comment('來訪事由（字典 guest_visit_reason 的值），未填為 0');
            $table->tinyInteger('reservation_type')->default(0)->comment('預約類型: 0:事前預約, 1:現場預約（臨時來訪）');
            $table->unsignedInteger('apply_count')->default(0)->comment('預約單數（已報到的預約單）');
            $table->unsignedInteger('visit_count')->default(0)->comment('來訪人次（guest_count 加總）');
            $table->unsignedInteger('card_count')->default(0)->comment('發證數（臨時卡）；目前每位來訪者配發一張，與 visit_count 相同');
            $table->timestamps();

            $table->unique(['stat_date', 'floor', 'doorplate_id', 'visit_reason', 'reservation_type'], 'guest_daily_stats_unique');
            $table->index('stat_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_daily_stats');
    }
};
