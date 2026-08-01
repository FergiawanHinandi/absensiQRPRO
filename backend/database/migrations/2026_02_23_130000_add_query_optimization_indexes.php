<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds indexes identified through query profiling
     * to optimize the top 10 slow queries in the system.
     */
    public function up(): void
    {
        // 1. Optimize attendance queries by school, date, and status
        // Guard: hanya buat jika tabel & kolom ada
        if (Schema::hasTable('attendances') &&
            Schema::hasColumn('attendances', 'school_id') &&
            Schema::hasColumn('attendances', 'attendance_date') &&
            Schema::hasColumn('attendances', 'status') &&
            !$this->indexExists('attendances', 'idx_attendances_school_date_status')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['school_id', 'attendance_date', 'status'], 'idx_attendances_school_date_status');
            });
        }

        // 2. Optimize student attendance lookups
        if (Schema::hasTable('attendances') &&
            Schema::hasColumn('attendances', 'student_id') &&
            Schema::hasColumn('attendances', 'attendance_date') &&
            !$this->indexExists('attendances', 'idx_attendances_student_date')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['student_id', 'attendance_date'], 'idx_attendances_student_date');
            });
        }

        // 3. Optimize schedule-based attendance queries
        if (Schema::hasTable('attendances') &&
            Schema::hasColumn('attendances', 'schedule_id') &&
            Schema::hasColumn('attendances', 'attendance_date') &&
            !$this->indexExists('attendances', 'idx_attendances_schedule_date')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['schedule_id', 'attendance_date'], 'idx_attendances_schedule_date');
            });
        }

        // 4. Optimize schedule queries by school
        // BUGFIX: Kolom 'date' tidak ada di tabel schedules — pakai 'day_of_week'
        if (Schema::hasTable('schedules') &&
            Schema::hasColumn('schedules', 'school_id') &&
            Schema::hasColumn('schedules', 'day_of_week') &&
            !$this->indexExists('schedules', 'idx_schedules_school_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->index(['school_id', 'day_of_week'], 'idx_schedules_school_date');
            });
        }

        // 5. Optimize schedule queries by class
        // BUGFIX: Gunakan 'day_of_week' bukan 'date'
        if (Schema::hasTable('schedules') &&
            Schema::hasColumn('schedules', 'class_id') &&
            Schema::hasColumn('schedules', 'day_of_week') &&
            !$this->indexExists('schedules', 'idx_schedules_class_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->index(['class_id', 'day_of_week'], 'idx_schedules_class_date');
            });
        }

        // 6. Optimize schedule queries by teacher
        // BUGFIX: Gunakan 'day_of_week' bukan 'date'
        if (Schema::hasTable('schedules') &&
            Schema::hasColumn('schedules', 'teacher_id') &&
            Schema::hasColumn('schedules', 'day_of_week') &&
            !$this->indexExists('schedules', 'idx_schedules_teacher_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->index(['teacher_id', 'day_of_week'], 'idx_schedules_teacher_date');
            });
        }

        // 7. Optimize class_students queries
        // BUGFIX: Project ini pakai class_students, bukan students langsung
        if (Schema::hasTable('class_students') &&
            Schema::hasColumn('class_students', 'class_id') &&
            !$this->indexExists('class_students', 'idx_students_school_class')) {
            Schema::table('class_students', function (Blueprint $table) {
                $table->index(['class_id'], 'idx_students_school_class');
            });
        }

        // 8. Skip idx_students_school_status (kolom students.status mungkin tidak ada)
        // Index ini di-skip untuk keamanan — kolom 'status' tidak selalu ada di semua tabel

        // 9. Skip tabel 'teachers' — project ini menggunakan users dengan role_type
        // Tidak ada tabel terpisah bernama 'teachers'

        // 10. Optimize QR code queries by expiration
        // BUGFIX: Kolom 'expires_at' tidak ada di qr_codes — pakai 'valid_until'
        if (Schema::hasTable('qr_codes') &&
            Schema::hasColumn('qr_codes', 'valid_until') &&
            Schema::hasColumn('qr_codes', 'school_id') &&
            !$this->indexExists('qr_codes', 'idx_qr_codes_expires_at')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                $table->index(['valid_until', 'school_id'], 'idx_qr_codes_expires_at');
            });
        }

        // 11. Optimize security event queries
        if (Schema::hasTable('security_events') &&
            Schema::hasColumn('security_events', 'school_id') &&
            Schema::hasColumn('security_events', 'created_at') &&
            !$this->indexExists('security_events', 'idx_security_events_school_created')) {
            Schema::table('security_events', function (Blueprint $table) {
                $table->index(['school_id', 'created_at'], 'idx_security_events_school_created');
            });
        }

        // 12. Optimize export progress queries
        if (Schema::hasTable('export_progress') &&
            Schema::hasColumn('export_progress', 'user_id') &&
            Schema::hasColumn('export_progress', 'status') &&
            !$this->indexExists('export_progress', 'idx_export_progress_user_status')) {
            Schema::table('export_progress', function (Blueprint $table) {
                $table->index(['user_id', 'status'], 'idx_export_progress_user_status');
            });
        }

        // 13. Optimize attendance summary queries
        if (Schema::hasTable('attendance_daily_class_summaries') &&
            Schema::hasColumn('attendance_daily_class_summaries', 'school_id') &&
            Schema::hasColumn('attendance_daily_class_summaries', 'attendance_date') &&
            !$this->indexExists('attendance_daily_class_summaries', 'idx_summaries_school_date')) {
            Schema::table('attendance_daily_class_summaries', function (Blueprint $table) {
                $table->index(['school_id', 'attendance_date'], 'idx_summaries_school_date');
            });
        }

        // 14. Optimize notification log queries
        if (Schema::hasTable('notification_logs') &&
            Schema::hasColumn('notification_logs', 'school_id') &&
            Schema::hasColumn('notification_logs', 'created_at') &&
            !$this->indexExists('notification_logs', 'idx_notification_logs_school_created')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index(['school_id', 'created_at'], 'idx_notification_logs_school_created');
            });
        }

        // 15. Optimize processed webhook queries
        if (Schema::hasTable('processed_webhooks') && !$this->indexExists('processed_webhooks', 'idx_processed_webhooks_order_status')) {
            Schema::table('processed_webhooks', function (Blueprint $table) {
                $table->index(['order_id', 'status'], 'idx_processed_webhooks_order_status');
            });
        }
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes in reverse order
        if (Schema::hasTable('processed_webhooks') && $this->indexExists('processed_webhooks', 'idx_processed_webhooks_order_status')) {
            Schema::table('processed_webhooks', function (Blueprint $table) {
                $table->dropIndex('idx_processed_webhooks_order_status');
            });
        }

        if (Schema::hasTable('notification_logs') && $this->indexExists('notification_logs', 'idx_notification_logs_school_created')) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->dropIndex('idx_notification_logs_school_created');
            });
        }

        if (Schema::hasTable('attendance_daily_class_summaries') && $this->indexExists('attendance_daily_class_summaries', 'idx_summaries_school_date')) {
            Schema::table('attendance_daily_class_summaries', function (Blueprint $table) {
                $table->dropIndex('idx_summaries_school_date');
            });
        }

        if (Schema::hasTable('export_progress') && $this->indexExists('export_progress', 'idx_export_progress_user_status')) {
            Schema::table('export_progress', function (Blueprint $table) {
                $table->dropIndex('idx_export_progress_user_status');
            });
        }

        if (Schema::hasTable('security_events') && $this->indexExists('security_events', 'idx_security_events_school_created')) {
            Schema::table('security_events', function (Blueprint $table) {
                $table->dropIndex('idx_security_events_school_created');
            });
        }

        if (Schema::hasTable('qr_codes') && $this->indexExists('qr_codes', 'idx_qr_codes_expires_at')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                $table->dropIndex('idx_qr_codes_expires_at');
            });
        }

        if ($this->indexExists('teachers', 'idx_teachers_school_status')) {
            Schema::table('teachers', function (Blueprint $table) {
                $table->dropIndex('idx_teachers_school_status');
            });
        }

        if ($this->indexExists('students', 'idx_students_school_status')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('idx_students_school_status');
            });
        }

        if ($this->indexExists('students', 'idx_students_school_class')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('idx_students_school_class');
            });
        }

        if ($this->indexExists('schedules', 'idx_schedules_teacher_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_teacher_date');
            });
        }

        if ($this->indexExists('schedules', 'idx_schedules_class_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_class_date');
            });
        }

        if ($this->indexExists('schedules', 'idx_schedules_school_date')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_school_date');
            });
        }

        if ($this->indexExists('attendances', 'idx_attendances_schedule_date')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropIndex('idx_attendances_schedule_date');
            });
        }

        if ($this->indexExists('attendances', 'idx_attendances_student_date')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropIndex('idx_attendances_student_date');
            });
        }

        if ($this->indexExists('attendances', 'idx_attendances_school_date_status')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropIndex('idx_attendances_school_date_status');
            });
        }
    }

    /**
     * Check if an index exists on a table.
     *
     * BUGFIX: getDoctrineSchemaManager() dihapus di Laravel 11.
     * Ganti dengan raw SQL ke pg_indexes untuk PostgreSQL.
     */
    private function indexExists(string $table, string $index): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                $result = \Illuminate\Support\Facades\DB::select(
                    "SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexname = ? LIMIT 1",
                    [$table, $index]
                );
                return !empty($result);
            }

            if ($driver === 'mysql') {
                $result = \Illuminate\Support\Facades\DB::select(
                    "SELECT 1 FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
                    [$table, $index]
                );
                return !empty($result);
            }

            // SQLite fallback
            $result = \Illuminate\Support\Facades\DB::select(
                "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ? LIMIT 1",
                [$table, $index]
            );
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }
};

