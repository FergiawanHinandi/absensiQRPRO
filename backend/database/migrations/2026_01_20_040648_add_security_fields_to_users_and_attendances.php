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
        Schema::table('users', function (Blueprint $table) {
            $table->string('device_id', 100)->nullable()->after('device_token')->comment('Hardware/App Specific Device ID');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('qr_code_id')->nullable()->after('schedule_id')->constrained('qr_codes')->onDelete('set null');
            $table->decimal('lat_in', 10, 8)->nullable()->after('check_in_time');
            $table->decimal('lng_in', 11, 8)->nullable()->after('lat_in');
            $table->string('device_id_in', 100)->nullable()->after('lng_in')->comment('Device ID used during scan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['qr_code_id']);
            $table->dropColumn(['qr_code_id', 'lat_in', 'lng_in', 'device_id_in']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('device_id');
        });
    }
};
