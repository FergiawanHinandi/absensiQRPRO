<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add Unique Attendance Constraint with School ID
 *
 * This migration adds a unique constraint on attendances table that includes school_id
 * to ensure proper multi-tenant data isolation and prevent duplicate attendance records.
 *
 * Constraint: (student_id, schedule_id, attendance_date, school_id)
 *
 * PREREQUISITES:
 * - Task 2.1: Query existing duplicates (completed)
 * - Task 2.2: Cleanup duplicate records (completed)
 *
 * SAFETY FEATURES:
 * - Validates no duplicates exist before adding constraint
 * - Drops old constraint if it exists
 * - Supports both MySQL and PostgreSQL
 * - Includes rollback support
 * - Logs all operations
 *
 * @see .kiro/specs/saas-hardening-30-days/requirements.md (Day 2)
 * @see .kiro/specs/saas-hardening-30-days/design.md (Day 2)
 * @author SaaS Hardening Team
 * @version 1.0.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('attendances')) {
            $this->logWarning('Table attendances does not exist. Skipping migration.');
            return;
        }

        // Verify required columns exist
        $requiredColumns = ['student_id', 'schedule_id', 'attendance_date', 'school_id'];
        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn('attendances', $column)) {
                $this->logError("Required column '{$column}' missing in attendances table. Skipping migration.");
                return;
            }
        }

        // ============================================================
        // STEP 1: CHECK FOR DUPLICATE DATA
        // ============================================================
        $this->logInfo('Step 1: Checking for duplicate attendance records...');
        
        $duplicates = $this->findDuplicates();

        if (count($duplicates) > 0) {
            $this->logError('Found ' . count($duplicates) . ' sets of duplicate attendance records!');
            
            // Log first 5 duplicates for debugging
            foreach (array_slice($duplicates, 0, 5) as $dup) {
                $this->logError(
                    "Duplicate - School: {$dup->school_id}, " .
                    "Student: {$dup->student_id}, " .
                    "Schedule: {$dup->schedule_id}, " .
                    "Date: {$dup->attendance_date} " .
                    "({$dup->duplicate_count} records)"
                );
            }

            if (count($duplicates) > 5) {
                $this->logError('... and ' . (count($duplicates) - 5) . ' more duplicate sets');
            }

            throw new \Exception(
                'Cannot add unique constraint with duplicate data. ' .
                'Please run: php artisan attendance:cleanup-duplicates'
            );
        }

        $this->logInfo('✅ No duplicates found. Safe to proceed.');

        // ============================================================
        // STEP 2: DROP OLD CONSTRAINTS (if they exist)
        // ============================================================
        $this->logInfo('Step 2: Removing old unique constraints...');
        
        Schema::table('attendances', function (Blueprint $table) {
            // Drop old constraint from original migration
            if ($this->indexExists('attendances', 'unique_attendance_per_schedule')) {
                $table->dropUnique('unique_attendance_per_schedule');
                $this->logInfo('Dropped old constraint: unique_attendance_per_schedule');
            }

            // Drop constraint from data integrity migration
            if ($this->indexExists('attendances', 'uniq_attendance_schedule_student_date')) {
                $table->dropUnique('uniq_attendance_schedule_student_date');
                $this->logInfo('Dropped old constraint: uniq_attendance_schedule_student_date');
            }
        });

        // ============================================================
        // STEP 3: ADD NEW UNIQUE CONSTRAINT WITH SCHOOL_ID
        // ============================================================
        $this->logInfo('Step 3: Adding new unique constraint with school_id...');
        
        Schema::table('attendances', function (Blueprint $table) {
            // Add unique constraint: (student_id, schedule_id, attendance_date, school_id)
            // This ensures proper multi-tenant isolation
            $table->unique(
                ['student_id', 'schedule_id', 'attendance_date', 'school_id'],
                'unique_attendance_per_day'
            );
        });

        $this->logInfo('✅ Successfully added unique constraint: unique_attendance_per_day');
        $this->logInfo('✅ Constraint columns: [student_id, schedule_id, attendance_date, school_id]');

        // ============================================================
        // STEP 4: VERIFY CONSTRAINT
        // ============================================================
        if ($this->indexExists('attendances', 'unique_attendance_per_day')) {
            $this->logInfo('✅ Constraint verified successfully');
        } else {
            $this->logWarning('⚠️  Could not verify constraint creation');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('attendances')) {
            return;
        }

        $this->logInfo('Rolling back unique constraint...');

        Schema::table('attendances', function (Blueprint $table) {
            // Drop the new constraint
            if ($this->indexExists('attendances', 'unique_attendance_per_day')) {
                $table->dropUnique('unique_attendance_per_day');
                $this->logInfo('Dropped constraint: unique_attendance_per_day');
            }

            // Restore old constraint (without school_id)
            // Note: This is for rollback only. The old constraint is less secure.
            if (!$this->indexExists('attendances', 'unique_attendance_per_schedule')) {
                $table->unique(
                    ['schedule_id', 'student_id', 'attendance_date'],
                    'unique_attendance_per_schedule'
                );
                $this->logInfo('Restored old constraint: unique_attendance_per_schedule');
            }
        });

        $this->logInfo('✅ Rollback completed');
    }

    /**
     * Find duplicate attendance records
     *
     * @return array
     */
    private function findDuplicates(): array
    {
        $driver = DB::connection()->getDriverName();
        
        // Use appropriate GROUP_CONCAT function based on database driver
        if ($driver === 'pgsql') {
            $groupConcat = "STRING_AGG(CAST(id AS TEXT), ',' ORDER BY id)";
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ORDER BY in GROUP_CONCAT
            $groupConcat = "GROUP_CONCAT(id, ',')";
        } else {
            // MySQL
            $groupConcat = "GROUP_CONCAT(id ORDER BY id)";
        }

        return DB::select("
            SELECT 
                student_id,
                schedule_id,
                attendance_date,
                school_id,
                COUNT(*) as duplicate_count,
                {$groupConcat} as attendance_ids,
                MIN(id) as keep_id
            FROM attendances
            WHERE deleted_at IS NULL
            GROUP BY student_id, schedule_id, attendance_date, school_id
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC, school_id, attendance_date DESC
            LIMIT 100
        ");
    }

    /**
     * Check if index/constraint exists on table
     *
     * @param string $table
     * @param string $index
     * @return bool
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        $driver = $connection->getDriverName();

        try {
            if ($driver === 'mysql') {
                $indexes = DB::select("
                    SELECT INDEX_NAME
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                    AND INDEX_NAME = ?
                ", [$databaseName, $table, $index]);

                return count($indexes) > 0;
            }

            if ($driver === 'pgsql') {
                $indexes = DB::select("
                    SELECT indexname
                    FROM pg_indexes
                    WHERE tablename = ?
                    AND indexname = ?
                ", [$table, $index]);

                return count($indexes) > 0;
            }

            if ($driver === 'sqlite') {
                $indexes = DB::select("
                    SELECT name
                    FROM sqlite_master
                    WHERE type = 'index'
                    AND tbl_name = ?
                    AND name = ?
                ", [$table, $index]);

                return count($indexes) > 0;
            }
        } catch (\Exception $e) {
            $this->logWarning("Could not check if index exists: {$e->getMessage()}");
        }

        // Fallback: assume doesn't exist
        return false;
    }

    /**
     * Log info message
     */
    private function logInfo(string $message): void
    {
        if (app()->runningInConsole()) {
            echo "[INFO] {$message}\n";
        }
        logger()->info("Migration: {$message}");
    }

    /**
     * Log warning message
     */
    private function logWarning(string $message): void
    {
        if (app()->runningInConsole()) {
            echo "[WARNING] {$message}\n";
        }
        logger()->warning("Migration: {$message}");
    }

    /**
     * Log error message
     */
    private function logError(string $message): void
    {
        if (app()->runningInConsole()) {
            echo "[ERROR] {$message}\n";
        }
        logger()->error("Migration: {$message}");
    }
};
