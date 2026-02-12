<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CRITICAL PERFORMANCE INDEXES
     * Based on database performance analysis for high-frequency queries
     */
    public function up(): void
    {
        // ================================================================
        // P0 CRITICAL INDEXES - Production Critical Performance
        // ================================================================

        // 1. Attendance duplicate check (MOST CRITICAL - every scan)
        // Query: SELECT * FROM attendances WHERE student_id = ? AND schedule_id = ? AND attendance_date = ?
        if (!$this->indexExists('attendances', 'idx_attendance_unique_scan')) {
            DB::statement('CREATE INDEX idx_attendance_unique_scan ON attendances (student_id, schedule_id, attendance_date, id)');
        }

        // 2. Daily report aggregation (HIGH IMPACT - admin dashboard)
        // Query: Aggregation queries with school_id + attendance_date + status
        if (!$this->indexExists('attendances', 'idx_attendance_daily_agg')) {
            DB::statement('CREATE INDEX idx_attendance_daily_agg ON attendances (school_id, attendance_date, status, student_id)');
        }

        // 3. Student attendance history (FREQUENT - student dashboard)
        // Query: WHERE student_id = ? ORDER BY attendance_date DESC
        if (!$this->indexExists('attendances', 'idx_attendance_student_timeline')) {
            DB::statement('CREATE INDEX idx_attendance_student_timeline ON attendances (student_id, attendance_date DESC, schedule_id, status)');
        }

        // ================================================================
        // P1 PERFORMANCE OPTIMIZATION INDEXES
        // ================================================================

        // 4. Monthly trend analysis (Principal dashboard)
        // Query: WHERE school_id = ? AND attendance_date >= ? GROUP BY DATE(attendance_date)
        if (!$this->indexExists('attendances', 'idx_attendance_monthly_analysis')) {
            DB::statement('CREATE INDEX idx_attendance_monthly_analysis ON attendances (school_id, attendance_date, status)');
        }

        // 5. Security events monitoring (Security dashboard)
        // Query: WHERE school_id = ? AND created_at >= ? ORDER BY created_at DESC
        // Only create if table exists (table created in later migration)
        if (Schema::hasTable('security_events') && !$this->indexExists('security_events', 'idx_security_events_monitoring')) {
            DB::statement('CREATE INDEX idx_security_events_monitoring ON security_events (school_id, created_at DESC, severity, event_type)');
        }

        // 6. Class-based attendance reports (Teacher dashboard)
        // Query: WHERE schedule_id = ? AND attendance_date = ?
        if (!$this->indexExists('attendances', 'idx_attendance_class_reports')) {
            DB::statement('CREATE INDEX idx_attendance_class_reports ON attendances (schedule_id, attendance_date, status, student_id)');
        }

        // 7. Security critical events with severity filter
        // Query: WHERE school_id = ? AND severity IN ('high', 'critical') ORDER BY created_at DESC
        // Only create if table exists (table created in later migration)
        if (Schema::hasTable('security_events') && !$this->indexExists('security_events', 'idx_security_events_critical')) {
            DB::statement('CREATE INDEX idx_security_events_critical ON security_events (school_id, severity, created_at DESC, user_id)');
        }

        // ================================================================
        // SUPPORTING INDEXES FOR JOINS
        // ================================================================

        // 8. Users lookup for security events (Foreign key optimization)
        if (!$this->indexExists('users', 'idx_users_security_lookup')) {
            DB::statement('CREATE INDEX idx_users_security_lookup ON users (id, name, email, role_type)');
        }

        // 9. Schedule-based queries optimization
        if (!$this->indexExists('schedules', 'idx_schedules_teacher_lookup')) {
            DB::statement('CREATE INDEX idx_schedules_teacher_lookup ON schedules (teacher_id, school_id, is_active)');
        }

        // 10. User role and school filtering (Multi-tenant optimization)
        if (!$this->indexExists('users', 'idx_users_role_school_active')) {
            DB::statement('CREATE INDEX idx_users_role_school_active ON users (role_type, school_id, is_active, id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes in reverse order
        $indexes = [
            'users' => ['idx_users_role_school_active', 'idx_users_security_lookup'],
            'schedules' => ['idx_schedules_teacher_lookup'],
            'security_events' => ['idx_security_events_critical', 'idx_security_events_monitoring'],
            'attendances' => [
                'idx_attendance_class_reports',
                'idx_attendance_monthly_analysis', 
                'idx_attendance_student_timeline',
                'idx_attendance_daily_agg',
                'idx_attendance_unique_scan'
            ]
        ];

        foreach ($indexes as $table => $tableIndexes) {
            // Only drop indexes if table exists
            if (Schema::hasTable($table)) {
                foreach ($tableIndexes as $index) {
                    if ($this->indexExists($table, $index)) {
                        DB::statement("DROP INDEX {$index}");
                    }
                }
            }
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'sqlite':
                $result = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$index]);
                return !empty($result);
            
            case 'mysql':
                $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);
                return !empty($result);
            
            case 'pgsql':
                $result = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $index]);
                return !empty($result);
            
            default:
                return false;
        }
    }
};