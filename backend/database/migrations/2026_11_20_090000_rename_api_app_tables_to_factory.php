<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 與原規劃（廠商模式／App Token，DEVELOP.MD 12.8）對齊命名：api_* → factory_*，app_key → app_secret。
// 之前的 migration（2026_11_19_170000／170100）已推送，不修改它，改用這支把已建好的表改名；
// 全新環境會先建 api_* 再改名，結果一致。
return new class extends Migration
{
    public function up(): void
    {
        foreach (['api_apps' => 'factory_apps', 'api_scopes' => 'factory_scopes', 'api_app_scopes' => 'factory_app_scopes', 'api_logs' => 'factory_logs'] as $old => $new) {
            if (Schema::hasTable($old) && ! Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }

        Schema::table('factory_apps', function (Blueprint $table) {
            if (Schema::hasColumn('factory_apps', 'app_key')) {
                $table->renameColumn('app_key', 'app_secret');
            }
        });

        Schema::table('factory_app_scopes', function (Blueprint $table) {
            if (Schema::hasColumn('factory_app_scopes', 'api_app_id')) {
                $table->renameColumn('api_app_id', 'factory_app_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('factory_app_scopes', function (Blueprint $table) {
            $table->renameColumn('factory_app_id', 'api_app_id');
        });

        Schema::table('factory_apps', function (Blueprint $table) {
            $table->renameColumn('app_secret', 'app_key');
        });

        foreach (['factory_apps' => 'api_apps', 'factory_scopes' => 'api_scopes', 'factory_app_scopes' => 'api_app_scopes', 'factory_logs' => 'api_logs'] as $old => $new) {
            Schema::rename($old, $new);
        }
    }
};
