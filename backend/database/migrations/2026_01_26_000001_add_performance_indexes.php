<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations - CRITICAL PERFORMANCE INDEXES
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendances (attendance_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_attendance_student ON attendances (student_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_school_date ON attendances (school_id, attendance_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_student_date ON attendances (student_id, attendance_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_attendance_created ON attendances (created_at)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_username ON users (username)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_school_active ON users (school_id, is_active)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_created ON users (created_at)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_qr_expires ON qr_codes (valid_until)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_qr_school_active ON qr_codes (school_id, is_active)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_qr_created ON qr_codes (created_at)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_schedule_day ON schedules (day_of_week)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_schedule_school_day ON schedules (school_id, day_of_week)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_schedule_start ON schedules (start_time)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_class_student ON class_students (student_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_class_id ON class_students (class_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_class_student_unique ON class_students (class_id, student_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_attendance_date');
        DB::statement('DROP INDEX IF EXISTS idx_attendance_student');
        DB::statement('DROP INDEX IF EXISTS idx_school_date');
        DB::statement('DROP INDEX IF EXISTS idx_student_date');
        DB::statement('DROP INDEX IF EXISTS idx_attendance_created');

        DB::statement('DROP INDEX IF EXISTS idx_users_email');
        DB::statement('DROP INDEX IF EXISTS idx_users_username');
        DB::statement('DROP INDEX IF EXISTS idx_users_school_active');
        DB::statement('DROP INDEX IF EXISTS idx_users_created');

        DB::statement('DROP INDEX IF EXISTS idx_qr_expires');
        DB::statement('DROP INDEX IF EXISTS idx_qr_school_active');
        DB::statement('DROP INDEX IF EXISTS idx_qr_created');

        DB::statement('DROP INDEX IF EXISTS idx_schedule_day');
        DB::statement('DROP INDEX IF EXISTS idx_schedule_school_day');
        DB::statement('DROP INDEX IF EXISTS idx_schedule_start');

        DB::statement('DROP INDEX IF EXISTS idx_class_student');
        DB::statement('DROP INDEX IF EXISTS idx_class_id');
        DB::statement('DROP INDEX IF EXISTS idx_class_student_unique');
    }
};
