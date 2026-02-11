<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add unique constraints to users table
        Schema::table('users', function (Blueprint $table) {
            // Drop existing unique constraint on username (global)
            if ($this->indexExists('users', 'users_username_unique')) {
                $table->dropUnique('users_username_unique');
            }
            
            // Drop existing unique constraint on email (global)
            if ($this->indexExists('users', 'users_email_unique')) {
                $table->dropUnique('users_email_unique');
            }
            
            // Add composite unique constraints (school-scoped)
            if (!$this->indexExists('users', 'unique_school_email')) {
                $table->unique(['school_id', 'email'], 'unique_school_email');
            }
            
            if (!$this->indexExists('users', 'unique_school_username')) {
                $table->unique(['school_id', 'username'], 'unique_school_username');
            }
        });

        // 2. Add composite index to schedules table
        Schema::table('schedules', function (Blueprint $table) {
            if (!$this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->index(
                    ['teacher_id', 'school_id', 'day_of_week', 'is_active'],
                    'idx_schedule_lookup'
                );
            }
        });

        // 3. Add composite index to attendances table
        Schema::table('attendances', function (Blueprint $table) {
            if (!$this->indexExists('attendances', 'idx_attendance_lookup')) {
                $table->index(
                    ['schedule_id', 'school_id', 'attendance_date'],
                    'idx_attendance_lookup'
                );
            }
        });

        // 4. Verify subscriptions index exists (already created in create migration)
        // Index (school_id, is_active, expires_at) already exists as 'idx_subscription_active'
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse users table changes
        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('users', 'unique_school_email')) {
                $table->dropUnique('unique_school_email');
            }
            
            if ($this->indexExists('users', 'unique_school_username')) {
                $table->dropUnique('unique_school_username');
            }
            
            // Restore original unique constraints
            if (!$this->indexExists('users', 'users_email_unique')) {
                $table->unique('email', 'users_email_unique');
            }
            
            if (!$this->indexExists('users', 'users_username_unique')) {
                $table->unique('username', 'users_username_unique');
            }
        });

        // Reverse schedules table changes
        Schema::table('schedules', function (Blueprint $table) {
            if ($this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->dropIndex('idx_schedule_lookup');
            }
        });

        // Reverse attendances table changes
        Schema::table('attendances', function (Blueprint $table) {
            if ($this->indexExists('attendances', 'idx_attendance_lookup')) {
                $table->dropIndex('idx_attendance_lookup');
            }
        });
    }

    /**
     * Check if an index exists (Driver Agnostic)
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = config('database.default');

        // PostgreSQL
        if ($driver === 'pgsql') {
            $result = DB::selectOne('
                SELECT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE tablename = ? AND indexname = ?
                ) as exists
            ', [$table, $indexName]);
            
            return $result->exists ?? false;
        }

        // MySQL / MariaDB
        if ($driver === 'mysql') {
            $result = DB::selectOne('
                SELECT COUNT(*) as cnt FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = ? AND index_name = ?
            ', [$table, $indexName]);

            return ($result->cnt ?? 0) > 0;
        }

        // SQLite
        if ($driver === 'sqlite') {
            $result = DB::selectOne("
                SELECT COUNT(*) as cnt FROM sqlite_master 
                WHERE type='index' AND tbl_name=? AND name=?
            ", [$table, $indexName]);
            
            return ($result->cnt ?? 0) > 0;
        }

        return false;
    }
};
