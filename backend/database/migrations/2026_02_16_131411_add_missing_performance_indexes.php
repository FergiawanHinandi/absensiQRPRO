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
     * This migration adds missing performance indexes identified in Task 13.2.
     * Based on EXPLAIN analysis and query pattern review.
     * 
     * Priority indexes added:
     * - P0: QR validation composite index
     * - P0: Subscription active check index
     * - P1: Student attendance history with DESC sorting
     * - P2: Teacher schedule lookup
     * 
     * @return void
     */
    public function up(): void
    {
        // ================================================================
        // P0 CRITICAL INDEXES
        // ================================================================
        
        // 1. QR Code Validation Composite Index
        // Query: WHERE qr_token = ? AND is_active = true AND valid_until > NOW()
        // Frequency: Every QR scan (highest frequency)
        if (Schema::hasTable('qr_codes')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                // Check if index doesn't already exist
                $indexName = 'idx_qr_codes_validation';
                if (!$this->indexExists('qr_codes', $indexName)) {
                    $table->index(
                        ['token', 'is_active', 'valid_until'],
                        $indexName
                    );
                }
            });
        }
        
        // 2. Active Subscription Check Index
        // BUGFIX: Tabel 'subscriptions' menggunakan kolom 'is_active' (boolean),
        // bukan 'status' (string). Index sebelumnya crash dengan:
        // SQLSTATE[42703]: Undefined column: 7 ERROR: column "status" does not exist
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'is_active')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $indexName = 'idx_subscriptions_active_check';
                if (!$this->indexExists('subscriptions', $indexName)) {
                    $table->index(
                        ['school_id', 'is_active', 'expires_at'],  // FIXED: 'status' → 'is_active'
                        $indexName
                    );
                }
            });
        }

        
        // ================================================================
        // P1 HIGH PRIORITY INDEXES
        // ================================================================
        
        // 3. Student Attendance History with DESC Sorting
        // Query: WHERE student_id = ? AND attendance_date >= ? ORDER BY attendance_date DESC
        // Frequency: Student dashboard, parent view
        // Note: DESC index optimization for sorted queries
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                $indexName = 'idx_attendance_student_history_sorted';
                if (!$this->indexExists('attendances', $indexName)) {
                    // Create index with DESC on attendance_date for optimal sorting
                    DB::statement(
                        "CREATE INDEX {$indexName} ON attendances (student_id, attendance_date DESC, status)"
                    );
                }
            });
        }
        
        // 4. Daily School Report Covering Index
        // Query: SELECT status, COUNT(*) FROM attendances WHERE school_id = ? AND attendance_date = ? GROUP BY status
        // Frequency: Admin dashboard (multiple times per day)
        // Note: Covering index includes all columns needed for query
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                $indexName = 'idx_attendance_daily_report_covering';
                if (!$this->indexExists('attendances', $indexName)) {
                    $table->index(
                        ['school_id', 'attendance_date', 'status', 'student_id'],
                        $indexName
                    );
                }
            });
        }
        
        // ================================================================
        // P2 MEDIUM PRIORITY INDEXES
        // ================================================================
        
        // 5. Teacher Schedule Lookup
        // Query: WHERE teacher_id = ? AND day_of_week = ? AND is_active = true
        // Frequency: Teacher dashboard load
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                $indexName = 'idx_schedules_teacher_daily';
                if (!$this->indexExists('schedules', $indexName)) {
                    $table->index(
                        ['teacher_id', 'day_of_week', 'is_active'],
                        $indexName
                    );
                }
            });
        }
        
        // 6. Active Users by Role and School
        // Query: WHERE school_id = ? AND role_type = ? AND is_active = true
        // Frequency: Admin user management
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $indexName = 'idx_users_school_role_active';
                if (!$this->indexExists('users', $indexName)) {
                    $table->index(
                        ['school_id', 'role_type', 'is_active'],
                        $indexName
                    );
                }
            });
        }
        
        // 7. Class Students Active List
        // Query: WHERE class_id = ? AND status = 'active'
        // Frequency: Class roster views
        if (Schema::hasTable('class_students')) {
            Schema::table('class_students', function (Blueprint $table) {
                $indexName = 'idx_class_students_active';
                if (!$this->indexExists('class_students', $indexName)) {
                    $table->index(
                        ['class_id', 'status', 'student_id'],
                        $indexName
                    );
                }
            });
        }
        
        // ================================================================
        // REMOVE UNUSED INDEXES (if any identified)
        // ================================================================
        
        // Note: Based on analysis, no unused indexes were identified.
        // All existing indexes are being utilized by query patterns.
        // Future optimization: Monitor index usage with pg_stat_user_indexes
        // and remove indexes with idx_scan = 0 after 30 days.
    }

    /**
     * Reverse the migrations.
     * 
     * @return void
     */
    public function down(): void
    {
        // Drop indexes in reverse order
        
        if (Schema::hasTable('class_students')) {
            Schema::table('class_students', function (Blueprint $table) {
                $table->dropIndex('idx_class_students_active');
            });
        }
        
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('idx_users_school_role_active');
            });
        }
        
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropIndex('idx_schedules_teacher_daily');
            });
        }
        
        if (Schema::hasTable('attendances')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropIndex('idx_attendance_daily_report_covering');
            });
        }
        
        if (Schema::hasTable('attendances')) {
            // Drop DESC index using raw SQL
            DB::statement("DROP INDEX IF EXISTS idx_attendance_student_history_sorted ON attendances");
        }
        
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex('idx_subscriptions_active_check');
            });
        }
        
        if (Schema::hasTable('qr_codes')) {
            Schema::table('qr_codes', function (Blueprint $table) {
                $table->dropIndex('idx_qr_codes_validation');
            });
        }
    }
    
    /**
     * Check if an index exists on a table.
     * 
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        // For MySQL/MariaDB
        if ($connection->getDriverName() === 'mysql') {
            $result = DB::select(
                "SELECT COUNT(*) as count 
                 FROM information_schema.STATISTICS 
                 WHERE TABLE_SCHEMA = ? 
                 AND TABLE_NAME = ? 
                 AND INDEX_NAME = ?",
                [$databaseName, $table, $indexName]
            );
            
            return $result[0]->count > 0;
        }
        
        // For PostgreSQL
        if ($connection->getDriverName() === 'pgsql') {
            $result = DB::select(
                "SELECT COUNT(*) as count 
                 FROM pg_indexes 
                 WHERE schemaname = 'public' 
                 AND tablename = ? 
                 AND indexname = ?",
                [$table, $indexName]
            );
            
            return $result[0]->count > 0;
        }
        
        // For SQLite (development)
        if ($connection->getDriverName() === 'sqlite') {
            $result = DB::select(
                "SELECT COUNT(*) as count 
                 FROM sqlite_master 
                 WHERE type = 'index' 
                 AND tbl_name = ? 
                 AND name = ?",
                [$table, $indexName]
            );
            
            return $result[0]->count > 0;
        }
        
        // Default: assume index doesn't exist (safe to create)
        return false;
    }
};
