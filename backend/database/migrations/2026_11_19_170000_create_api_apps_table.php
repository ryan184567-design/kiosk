<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_apps', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 40)->unique()->comment('對外 appid（隨機）');
            $table->string('name', 100)->comment('名稱，如：自助訪客機 1 號');
            $table->text('app_key')->comment('加密存放的 appkey（需可還原以重算 HMAC）');
            $table->boolean('status')->default(true)->comment('1:啟用, 0:停用');
            $table->json('allowed_ips')->nullable()->comment('IP/CIDR 白名單，空代表不限制');
            $table->timestamp('expired_at')->nullable()->comment('App 過期時間');
            $table->string('remark', 255)->nullable();
            $table->timestamp('last_used_at')->nullable()->comment('最近簽發時間');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('api_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50)->index()->comment('模塊，如 guest');
            $table->string('code', 100)->unique()->comment('完整 scope，如 guest.check');
            $table->string('name', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // scope 存字串而非外鍵：授權需要支援 `模塊.*` 與 `*` 通配，這兩種不會登記在 api_scopes。
        Schema::create('api_app_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_app_id');
            $table->string('scope', 100);
            $table->timestamps();

            $table->unique(['api_app_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_app_scopes');
        Schema::dropIfExists('api_scopes');
        Schema::dropIfExists('api_apps');
    }
};
