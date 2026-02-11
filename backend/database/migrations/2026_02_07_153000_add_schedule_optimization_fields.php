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
        // Add timezone to schools table if not exists
        if (!Schema::hasColumn('schools', 'timezone')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->string('timezone', 50)->default('Asia/Jakarta')->after('address');
                $table->index('timezone');
            });
        }

        // Add is_active and schedule_type to schedules table if not exists
        Schema::table('schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('schedules', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('room');
            }
            
            if (!Schema::hasColumn('schedules', 'schedule_type')) {
                $table->enum('schedule_type', ['regular', 'substitute', 'extra'])->default('regular')->after('is_active');
            }

            // Create composite index for optimized teacher schedule queries
            // This index will be used for: WHERE teacher_id = ? AND school_id = ? AND day_of_week = ?
            if (!Schema::hasIndex('schedules', 'idx_teacher_school_day')) {
                $table->index(['teacher_id', 'school_id', 'day_of_week'], 'idx_teacher_school_day');
            }

            // Additional index for active regular schedules
            if (!Schema::hasIndex('schedules', 'idx_active_regular')) {
                $table->index(['is_active', 'schedule_type'], 'idx_active_regular');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_school_day');
            $table->dropIndex('idx_active_regular');
            $table->dropColumn(['is_active', 'schedule_type']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropIndex(['timezone']);
            $table->dropColumn('timezone');
        });
    }
};
