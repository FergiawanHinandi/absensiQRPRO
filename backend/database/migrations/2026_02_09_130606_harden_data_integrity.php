<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Integrity Hardening Migration
 *
 * Adds unique constraints and indexes to ensure data integrity
 * without breaking existing data.
 *
 * SAFETY FEATURES:
 * - Checks for existing indexes/constraints before creating
 * - Validates no duplicate data exists before adding unique constraints
 * - Safe for production deployment
 * - Includes rollback support
 *
 * @author Database Reliability Engineer
 * @version 1.0.0
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ============================================================
        // STEP 1: ATTENDANCES TABLE
        // ============================================================
        $this->hardenAttendancesTable();

        // ============================================================
        // STEP 2: SCHEDULES TABLE
        // ============================================================
        $this->hardenSchedulesTable();

        // ============================================================
        // STEP 3: USERS TABLE
        // ============================================================
        $this->hardenUsersTable();

        // ============================================================
        // STEP 4: SUBSCRIPTIONS TABLE
        // ============================================================
        $this->hardenSubscriptionsTable();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ============================================================
        // ROLLBACK ATTENDANCES
        // ============================================================
        Schema::table('attendances', function (Blueprint $table) {
            // Drop unique constraint
            if ($this->indexExists('attendances', 'uniq_attendance_schedule_student_date')) {
                $table->dropUnique('uniq_attendance_schedule_student_date');
            }

            // Drop index
            if ($this->indexExists('attendances', 'idx_attendance_lookup')) {
                $table->dropIndex('idx_attendance_lookup');
            }
        });

        // ============================================================
        // ROLLBACK SCHEDULES
        // ============================================================
        Schema::table('schedules', function (Blueprint $table) {
            if ($this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->dropIndex('idx_schedule_lookup');
            }
        });

        // ============================================================
        // ROLLBACK USERS
        // ============================================================
        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('users', 'users_school_id_email_unique')) {
                $table->dropUnique('users_school_id_email_unique');
            }

            if ($this->indexExists('users', 'users_school_id_username_unique')) {
                $table->dropUnique('users_school_id_username_unique');
            }
        });

        // ============================================================
        // ROLLBACK SUBSCRIPTIONS
        // ============================================================
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if ($this->indexExists('subscriptions', 'idx_subscription_lookup')) {
                    $table->dropIndex('idx_subscription_lookup');
                }
            });
        }
    }

    /**
     * Harden attendances table
     */
    private function hardenAttendancesTable(): void
    {
        if (!Schema::hasTable('attendances')) {
            logger()->warning('Migration: Table attendances does not exist. Skipping.');
            return;
        }

        // Verify required columns exist
        if (!Schema::hasColumn('attendances', 'schedule_id') ||
            !Schema::hasColumn('attendances', 'student_id') ||
            !Schema::hasColumn('attendances', 'attendance_date')) {
            logger()->error('Migration: Required columns missing in attendances table. Skipping.');
            return;
        }

        // ============================================================
        // CHECK FOR DUPLICATE DATA BEFORE ADDING UNIQUE CONSTRAINT
        // ============================================================
        $duplicates = DB::select("
            SELECT
                schedule_id,
                student_id,
                DATE(attendance_date) as date,
                COUNT(*) as count
            FROM attendances
            GROUP BY schedule_id, student_id, DATE(attendance_date)
            HAVING COUNT(*) > 1
        ");

        if (count($duplicates) > 0) {
            logger()->error('Migration: Found ' . count($duplicates) . ' duplicate attendance records!');

            foreach (array_slice($duplicates, 0, 5) as $dup) {
                logger()->error("Migration: Duplicate - Schedule: {$dup->schedule_id}, Student: {$dup->student_id}, Date: {$dup->date} ({$dup->count} records)");
            }

            if (count($duplicates) > 5) {
                logger()->error('Migration: ... and ' . (count($duplicates) - 5) . ' more duplicates');
            }

            throw new \Exception('Cannot add unique constraint with duplicate data. Clean up duplicates first.');
        }

        Schema::table('attendances', function (Blueprint $table) {
            // Add unique constraint (prevents duplicate attendance)
            if (!$this->indexExists('attendances', 'uniq_attendance_schedule_student_date')) {
                $table->unique(
                    ['schedule_id', 'student_id', 'attendance_date'],
                    'uniq_attendance_schedule_student_date'
                );
                logger()->info('Migration: Added unique constraint: uniq_attendance_schedule_student_date');
            }

            // Add composite index (optimizes lookups)
            if (!$this->indexExists('attendances', 'idx_attendance_lookup')) {
                $table->index(
                    ['schedule_id', 'school_id', 'attendance_date'],
                    'idx_attendance_lookup'
                );
                logger()->info('Migration: Added index: idx_attendance_lookup');
            }
        });
    }

    /**
     * Harden schedules table
     */
    private function hardenSchedulesTable(): void
    {
        if (!Schema::hasTable('schedules')) {
            logger()->warning('Migration: Table schedules does not exist. Skipping.');
            return;
        }

        // Verify required columns exist
        if (!Schema::hasColumn('schedules', 'teacher_id') ||
            !Schema::hasColumn('schedules', 'school_id') ||
            !Schema::hasColumn('schedules', 'day_of_week') ||
            !Schema::hasColumn('schedules', 'is_active')) {
            logger()->error('Migration: Required columns missing in schedules table. Skipping.');
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            // Add composite index (optimizes teacher schedule lookups)
            if (!$this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->index(
                    ['teacher_id', 'school_id', 'day_of_week', 'is_active'],
                    'idx_schedule_lookup'
                );
                logger()->info('Migration: Added index: idx_schedule_lookup');
            }
        });
    }

    /**
     * Harden users table
     */
    private function hardenUsersTable(): void
    {
        if (!Schema::hasTable('users')) {
            logger()->warning('Migration: Table users does not exist. Skipping.');
            return;
        }

        // Verify required columns exist
        if (!Schema::hasColumn('users', 'school_id') ||
            !Schema::hasColumn('users', 'email') ||
            !Schema::hasColumn('users', 'username')) {
            logger()->error('Migration: Required columns missing in users table. Skipping.');
            return;
        }

        // ============================================================
        // CHECK FOR DUPLICATE EMAILS WITHIN SAME SCHOOL
        // ============================================================
        $duplicateEmails = DB::select("
            SELECT
                school_id,
                email,
                COUNT(*) as count
            FROM users
            WHERE email IS NOT NULL
            GROUP BY school_id, email
            HAVING COUNT(*) > 1
        ");

        if (count($duplicateEmails) > 0) {
            logger()->error('Migration: Found ' . count($duplicateEmails) . ' duplicate email addresses within schools!');

            foreach (array_slice($duplicateEmails, 0, 5) as $dup) {
                logger()->error("Migration: Duplicate - School: {$dup->school_id}, Email: {$dup->email} ({$dup->count} records)");
            }

            throw new \Exception('Cannot add unique constraint with duplicate emails. Clean up duplicates first.');
        }

        // ============================================================
        // CHECK FOR DUPLICATE USERNAMES WITHIN SAME SCHOOL
        // ============================================================
        $duplicateUsernames = DB::select("
            SELECT
                school_id,
                username,
                COUNT(*) as count
            FROM users
            WHERE username IS NOT NULL
            GROUP BY school_id, username
            HAVING COUNT(*) > 1
        ");

        if (count($duplicateUsernames) > 0) {
            logger()->error('Migration: Found ' . count($duplicateUsernames) . ' duplicate usernames within schools!');

            foreach (array_slice($duplicateUsernames, 0, 5) as $dup) {
                logger()->error("Migration: Duplicate - School: {$dup->school_id}, Username: {$dup->username} ({$dup->count} records)");
            }

            throw new \Exception('Cannot add unique constraint with duplicate usernames. Clean up duplicates first.');
        }

        Schema::table('users', function (Blueprint $table) {
            // Add unique constraint for email per school
            if (!$this->indexExists('users', 'users_school_id_email_unique')) {
                $table->unique(['school_id', 'email'], 'users_school_id_email_unique');
                logger()->info('Migration: Added unique constraint: users_school_id_email_unique');
            }

            // Add unique constraint for username per school
            if (!$this->indexExists('users', 'users_school_id_username_unique')) {
                $table->unique(['school_id', 'username'], 'users_school_id_username_unique');
                logger()->info('Migration: Added unique constraint: users_school_id_username_unique');
            }
        });
    }

    /**
     * Harden subscriptions table
     */
    private function hardenSubscriptionsTable(): void
    {
        if (!Schema::hasTable('subscriptions')) {
            logger()->warning('Migration: Table subscriptions does not exist. Skipping.');
            return;
        }

        // Verify required columns exist
        if (!Schema::hasColumn('subscriptions', 'school_id') ||
            !Schema::hasColumn('subscriptions', 'is_active') ||
            !Schema::hasColumn('subscriptions', 'expires_at')) {
            logger()->error('Migration: Required columns missing in subscriptions table. Skipping.');
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            // Add composite index (optimizes subscription lookups)
            if (!$this->indexExists('subscriptions', 'idx_subscription_lookup')) {
                $table->index(
                    ['school_id', 'is_active', 'expires_at'],
                    'idx_subscription_lookup'
                );
                logger()->info('Migration: Added index: idx_subscription_lookup');
            }
        });
    }

    /**
     * Check if index exists on table
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

        // Fallback: assume doesn't exist
        return false;
    }
};
