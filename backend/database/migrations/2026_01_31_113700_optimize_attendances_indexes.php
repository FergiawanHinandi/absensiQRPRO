<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Performance Optimization Migration for Attendances Table
 *
 * This migration adds composite indexes and optimizations for high-volume school usage.
 *
 * PERFORMANCE ANALYSIS:
 * - Expected volume: 500 students × 6 classes/day × 200 school days = 600,000 rows/year/school
 * - With 100 schools: 60 million rows/year
 *
 * INDEX STRATEGY:
 * 1. Composite indexes for common query patterns
 * 2. Partial indexes for active records only
 * 3. Covering indexes to avoid table lookups
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Add class_id if not exists (Performance Denormalization)
            if (!Schema::hasColumn('attendances', 'class_id')) {
                $table->foreignId('class_id')->nullable()->after('student_id')->constrained('classes')->nullOnDelete();
            }

            // ================================================================
            // COMPOSITE INDEX 1: Student Attendance History
            // ================================================================
            // Query pattern: WHERE student_id = ? ORDER BY attendance_date DESC
            // Used by: Student dashboard, parent view, attendance history API
            // Why: Allows fetching student's attendance sorted by date in one index scan
            if (!$this->indexExists('attendances', 'idx_attendance_student_date_desc')) {
                $table->index(
                    ['student_id', 'attendance_date', 'status'],
                    'idx_attendance_student_date_desc'
                );
            }

            // ================================================================
            // COMPOSITE INDEX 2: Schedule + Date (Daily Class Report)
            // ================================================================
            // Query pattern: WHERE schedule_id = ? AND attendance_date = ?
            // Used by: Teacher class view, real-time attendance monitoring
            // Why: Allows fetching all students' attendance for a specific class/day
            // Note: Already exists as unique constraint, but adding with status for covering
            if (!$this->indexExists('attendances', 'idx_attendance_schedule_date_status')) {
                $table->index(
                    ['schedule_id', 'attendance_date', 'status'],
                    'idx_attendance_schedule_date_status'
                );
            }

            // ================================================================
            // COMPOSITE INDEX 3: School + Date Range (Admin Reports)
            // ================================================================
            // Query pattern: WHERE school_id = ? AND attendance_date BETWEEN ? AND ?
            // Used by: Admin daily/weekly/monthly reports, dashboard stats
            // Why: Allows efficient range scans for school-wide reports
            if (!$this->indexExists('attendances', 'idx_attendance_school_date_range')) {
                $table->index(
                    ['school_id', 'attendance_date', 'status', 'student_id'],
                    'idx_attendance_school_date_range'
                );
            }

            // ================================================================
            // INDEX 4: Created At (Recent Activity, Audit Log)
            // ================================================================
            // Query pattern: ORDER BY created_at DESC LIMIT N
            // Used by: Recent activity feed, real-time monitoring, audit logs
            // Why: Allows efficient fetching of most recent check-ins
            if (!$this->indexExists('attendances', 'idx_attendance_created_at')) {
                $table->index(['created_at'], 'idx_attendance_created_at');
            }

            // ================================================================
            // COMPOSITE INDEX 5: Student + Schedule (Duplicate Check)
            // ================================================================
            // Query pattern: WHERE student_id = ? AND schedule_id = ? AND attendance_date = ?
            // Used by: Duplicate prevention during check-in (most critical for performance)
            // Why: Allows instant lookup to check if student already checked in
            // Note: This should be covered by unique constraint but explicit index helps
            if (!$this->indexExists('attendances', 'idx_attendance_duplicate_check')) {
                $table->index(
                    ['student_id', 'schedule_id', 'attendance_date'],
                    'idx_attendance_duplicate_check'
                );
            }

            // ================================================================
            // INDEX 6: Monthly Summary (Aggregation Queries)
            // ================================================================
            // Query pattern: GROUP BY student_id, EXTRACT(MONTH FROM attendance_date)
            // Used by: Monthly attendance reports, student performance summaries
            // Why: Optimizes aggregation queries for monthly statistics
            if (!$this->indexExists('attendances', 'idx_attendance_monthly')) {
                $table->index(
                    ['school_id', 'student_id', 'attendance_date'],
                    'idx_attendance_monthly'
                );
            }

            // ================================================================
            // INDEX 7: Status Filter (Status-based queries)
            // ================================================================
            // Query pattern: WHERE status IN ('absent', 'late') AND school_id = ?
            // Used by: Alert systems, truancy reports, late student reports
            // Why: Quickly find problematic attendance records
            if (!$this->indexExists('attendances', 'idx_attendance_status_school')) {
                $table->index(
                    ['status', 'school_id', 'attendance_date'],
                    'idx_attendance_status_school'
                );
            }

            // ================================================================
            // INDEX 8: Class-based queries
            // ================================================================
            // Query pattern: WHERE class_id = ? AND attendance_date = ?
            // Used by: Homeroom teacher reports, class-level statistics
            if (!$this->indexExists('attendances', 'idx_attendance_class_date')) {
                $table->index(
                    ['class_id', 'attendance_date', 'status'],
                    'idx_attendance_class_date'
                );
            }
        });

        // ================================================================
        // PostgreSQL-SPECIFIC: Partial Index for Today's Attendance
        // ================================================================
        // This creates a smaller, faster index for today's records only
        // Dramatically speeds up real-time dashboard queries
        if (config('database.default') === 'pgsql') {
            // Commenting out due to IMMUTABLE function error with CURRENT_DATE in index predicate
            // DB::statement("
            //     CREATE INDEX IF NOT EXISTS idx_attendance_today
            //     ON attendances (school_id, student_id, status)
            //     WHERE attendance_date = CURRENT_DATE
            // ");

            // Add partial index for non-deleted records
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_attendance_active
                ON attendances (school_id, attendance_date, status)
                WHERE deleted_at IS NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop in reverse order
            $indexes = [
                'idx_attendance_class_date',
                'idx_attendance_status_school',
                'idx_attendance_monthly',
                'idx_attendance_duplicate_check',
                'idx_attendance_created_at',
                'idx_attendance_school_date_range',
                'idx_attendance_schedule_date_status',
                'idx_attendance_student_date_desc',
            ];

            foreach ($indexes as $index) {
                if ($this->indexExists('attendances', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        // Drop PostgreSQL partial indexes
        if (config('database.default') === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_attendance_today');
            DB::statement('DROP INDEX IF EXISTS idx_attendance_active');
        }
    }

    /**
     * Check if an index exists
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = config('database.default');

        if ($driver === 'pgsql') {
            return DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE tablename = ? AND indexname = ?
                ) as exists
            ", [$table, $indexName])->exists ?? false;
        }

        if ($driver === 'mysql') {
            $result = DB::selectOne("
                SELECT COUNT(*) as cnt FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ? AND index_name = ?
            ", [$table, $indexName]);
            return ($result->cnt ?? 0) > 0;
        }

        // SQLite - try/catch approach
        return false;
    }
};
