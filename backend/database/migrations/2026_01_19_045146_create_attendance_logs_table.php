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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->onDelete('cascade');
            $table->foreignId('qr_code_id')->nullable()->constrained('qr_codes')->onDelete('set null');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('action', ['scan_in', 'scan_out', 'manual_create', 'manual_update', 'approve', 'reject']);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->float('location_accuracy')->nullable()->comment('GPS accuracy in meters');
            $table->json('device_info')->nullable()->comment('Device fingerprint');
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            // Indexes
            $table->index('attendance_id', 'idx_attendance_logs_attendance');
            $table->index(['user_id', 'created_at'], 'idx_attendance_logs_user');
            $table->index(['action', 'created_at'], 'idx_attendance_logs_action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
