<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CRITICAL: This migration modifies unique constraints.
     * DO NOT ROLLBACK IN PRODUCTION - data loss will occur.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // 1. Add attendance_type column
            // Using string instead of ENUM for PostgreSQL flexibility
            $table->string('attendance_type', 10)
                ->default('in')
                ->after('status')
                ->comment('Check-in or check-out: in|out');

            // 2. Drop old unique constraint by explicit name
            // IMPORTANT: Do NOT use array of columns - will fail in PostgreSQL
            $table->dropUnique('unique_attendance_per_schedule');

            // 3. Add new unique constraint with attendance_type
            $table->unique(
                ['schedule_id', 'student_id', 'attendance_date', 'attendance_type'],
                'unique_attendance_with_type'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * WARNING: DO NOT ROLLBACK THIS MIGRATION IN PRODUCTION!
     * Rolling back will:
     * - Delete all check-out data (attendance_type column)
     * - Potentially create constraint conflicts
     * - Cause data integrity issues
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Reverse: remove new constraint and column
            $table->dropUnique('unique_attendance_with_type');

            // Restore old constraint
            $table->unique(
                ['schedule_id', 'student_id', 'attendance_date'],
                'unique_attendance_per_schedule'
            );

            $table->dropColumn('attendance_type');
        });
    }
};
