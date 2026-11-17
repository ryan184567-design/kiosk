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
        Schema::table('doorplates', function (Blueprint $table) {
            $table->string('company_name', 255)->nullable()->after('household');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doorplates', function (Blueprint $table) {
            $table->dropColumn('company_name');
        });
    }
};
