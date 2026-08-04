<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make schools.timezone nullable so TimezoneHelper can fall back
     * to the application default timezone when a school has no timezone.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('timezone', 50)->nullable()->default('Asia/Jakarta')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('timezone', 50)->default('Asia/Jakarta')->nullable(false)->change();
        });
    }
};
