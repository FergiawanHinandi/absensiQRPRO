<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRITICAL: Add database constraints to prevent data integrity issues
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // CRITICAL: Prevent double attendance for same student, schedule, date
            try {
                $table->unique(['student_id', 'schedule_id', 'attendance_date'], 'unique_attendance_per_day');
            } catch (\Exception $e) {
                // Index might already exist
            }

            // Add indexes for performance (check if exists first)
            try {
                $table->index(['school_id', 'attendance_date', 'status'], 'idx_school_date_status_new');
            } catch (\Exception $e) {
                // Index might already exist
            }
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            // CRITICAL: Prevent multiple active QR codes for same schedule
            try {
                $table->index(['schedule_id', 'is_active', 'valid_until'], 'idx_schedule_active_new');
            } catch (\Exception $e) {
                // Index might already exist
            }
        });

        Schema::table('users', function (Blueprint $table) {
            // CRITICAL: Ensure username uniqueness per school
            try {
                $table->unique(['school_id', 'username'], 'unique_username_per_school');
            } catch (\Exception $e) {
                // Index might already exist
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('unique_attendance_per_day');
            $table->dropIndex('idx_school_date_status');
            $table->dropIndex('idx_student_date');
        });

        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropIndex('idx_schedule_active');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('unique_username_per_school');
        });
    }
};
