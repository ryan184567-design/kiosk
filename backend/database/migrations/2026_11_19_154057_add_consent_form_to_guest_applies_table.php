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
        Schema::table('guest_applies', function (Blueprint $table) {
            $table->boolean('consent_form')->after('reservation_status')->comment('訪客須知與同意條款: 0:未同意 1:同意');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_applies', function (Blueprint $table) {
            $table->dropColumn('consent_form');
        });
    }
};
