<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 系統環境配置表，見 README 17。存在各站點自己的資料庫，site_id 不用於跨站區分。
//
// 見 README 17.1：cfg_type 原本用 MySQL enum（只有 6 種類型），17.1 的文件
// 規格本來就寫 varchar(20)，這裡補齊文件與實際結構的一致性——放寬成
// varchar(20) 後，未來新增類型只需要更新 SystemConfig::TYPES（應用層驗證，
// SystemConfigService::assertValidValue 的 default 會擋未知類型）與 seeder
// 灌的 system_cfg_type 字典項目，不需要每次都改表結構。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_configs', function (Blueprint $table) {
            $table->string('cfg_type', 20)
                ->comment('類型：string/textarea/int/float/bool/enum/multi/dic/dic_multi/date/datetime/time/email/url/color/password/json，見 README 17.1')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('system_configs', function (Blueprint $table) {
            $table->enum('cfg_type', ['string', 'int', 'enum', 'dic', 'date', 'datetime'])
                ->comment('類型：string/int/enum/dic/date/datetime（擴充前的原始清單）')
                ->change();
        });
    }
};