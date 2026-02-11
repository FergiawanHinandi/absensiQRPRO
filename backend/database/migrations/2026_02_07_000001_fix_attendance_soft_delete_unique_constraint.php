<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX: Attendance Unique Constraint for Soft Delete Compatibility
 *
 * PROBLEM:
 * - Previous migrations created conflicting unique constraints
 * - `uk_attendance_student_schedule_date` is a REGULAR unique (blocks soft-deleted records)
 * - `uk_attendance_active_student_schedule` is a partial unique (PostgreSQL only)
 * - This causes "duplicate key" errors when trying to re-insert after soft delete
 *
 * SOLUTION:
 * - Drop ALL non-partial unique constraints on attendances
 * - Keep or create partial unique index (WHERE deleted_at IS NULL) for PostgreSQL
 * - For MySQL: Use composite unique including COALESCE(deleted_at, 0) or trigger approach
 * - For SQLite: Application-level enforcement (tests)
 *
 * BUSINESS RULE:
 * - A student can only have ONE active attendance per schedule per day
 * - Soft-deleted records should NOT block new inserts
 * - Re-attending after correction/deletion should be allowed
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ================================================================
        // STEP 1: Drop ALL conflicting non-partial unique constraints
        // ================================================================
        $constraintsToDrop = [
            'uk_attendance_student_schedule_date',           // From harden migration
            'unique_attendance_per_schedule',                // From original create
            'unique_attendance_with_type',                   // From fix migration
            'unique_attendance_per_day',                     // From critical constraints
            'attendances_schedule_id_student_id_attendance_date_unique', // Auto-generated
            'attendances_student_id_schedule_id_attendance_date_unique', // Auto-generated variant
        ];

        foreach ($constraintsToDrop as $indexName) {
            $this->dropIndexSafely('attendances', $indexName);
        }

        // ================================================================
        // STEP 2: Create proper soft-delete-safe unique constraint
        // ================================================================
        if ($driver === 'pgsql') {
            // PostgreSQL: Partial unique index (best solution)
            // This allows multiple soft-deleted records but only ONE active record
            $this->dropIndexSafely('attendances', 'uk_attendance_active_student_schedule');
            $this->dropIndexSafely('attendances', 'uk_attendance_soft_delete_safe');

            DB::statement('
                CREATE UNIQUE INDEX uk_attendance_soft_delete_safe
                ON attendances (student_id, schedule_id, attendance_date)
                WHERE deleted_at IS NULL
            ');

            // Also create partial index for attendance_type combinations
            $this->dropIndexSafely('attendances', 'uk_attendance_type_soft_delete_safe');
            DB::statement('
                CREATE UNIQUE INDEX uk_attendance_type_soft_delete_safe
                ON attendances (student_id, attendance_date, attendance_type)
                WHERE deleted_at IS NULL AND schedule_id IS NULL
            ');

            // Add comment for documentation
            DB::statement("
                COMMENT ON INDEX uk_attendance_soft_delete_safe IS 
                'Partial unique: allows re-insert after soft delete. Only active records are checked.'
            ");

        } elseif ($driver === 'mysql') {
            // MySQL 8.0.13+: Use functional index with COALESCE
            // MySQL doesn't support partial indexes, so we use a generated column approach

            // First, ensure we have a generated column for unique checking
            if (!Schema::hasColumn('attendances', 'active_unique_key')) {
                Schema::table('attendances', function (Blueprint $table) {
                    // This column is NULL when deleted, breaking the unique constraint
                    // Active records get a computed value, deleted records get NULL
                    $table->string('active_unique_key', 100)
                        ->nullable()
                        ->storedAs("CASE WHEN deleted_at IS NULL 
                            THEN CONCAT(student_id, '-', COALESCE(schedule_id, 0), '-', attendance_date) 
                            ELSE NULL END")
                        ->after('deleted_at');
                });

                // Create unique index on the generated column
                // NULL values are not considered duplicates in MySQL
                Schema::table('attendances', function (Blueprint $table) {
                    $table->unique('active_unique_key', 'uk_attendance_soft_delete_safe');
                });
            }

        } elseif ($driver === 'sqlite') {
            // SQLite: Limited support for partial indexes in older versions
            // Try partial index first (SQLite 3.8.0+)
            try {
                DB::statement('DROP INDEX IF EXISTS uk_attendance_soft_delete_safe');
                DB::statement('
                    CREATE UNIQUE INDEX uk_attendance_soft_delete_safe
                    ON attendances (student_id, schedule_id, attendance_date)
                    WHERE deleted_at IS NULL
                ');
            } catch (\Exception $e) {
                // Fallback: Application-level enforcement for older SQLite
                // The Attendance model should handle this via beforeSave hook
                \Illuminate\Support\Facades\Log::warning(
                    'SQLite partial index not supported, using application-level enforcement',
                    ['error' => $e->getMessage()]
                );
            }
        }

        // ================================================================
        // STEP 3: Add supporting indexes for soft delete queries
        // ================================================================
        if ($driver !== 'sqlite') {
            // Index to speed up "active attendance" queries
            $this->createIndexSafely(
                'attendances',
                ['student_id', 'attendance_date', 'deleted_at'],
                'idx_attendance_student_date_deleted'
            );

            // Index for schedule-based queries with soft delete
            $this->createIndexSafely(
                'attendances',
                ['schedule_id', 'attendance_date', 'deleted_at'],
                'idx_attendance_schedule_date_deleted'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        // Drop soft-delete-safe indexes
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS uk_attendance_soft_delete_safe');
            DB::statement('DROP INDEX IF EXISTS uk_attendance_type_soft_delete_safe');
        }

        if ($driver === 'mysql') {
            $this->dropIndexSafely('attendances', 'uk_attendance_soft_delete_safe');

            if (Schema::hasColumn('attendances', 'active_unique_key')) {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->dropColumn('active_unique_key');
                });
            }
        }

        // Drop supporting indexes
        $this->dropIndexSafely('attendances', 'idx_attendance_student_date_deleted');
        $this->dropIndexSafely('attendances', 'idx_attendance_schedule_date_deleted');

        // Restore original unique constraint (non-partial)
        // WARNING: This may fail if soft-deleted duplicates exist
        try {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unique(
                    ['student_id', 'schedule_id', 'attendance_date'],
                    'uk_attendance_student_schedule_date'
                );
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Could not restore original unique constraint - soft deleted duplicates may exist',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Safely drop an index if it exists
     */
    private function dropIndexSafely(string $table, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'pgsql') {
                // PostgreSQL: Check if constraint exists first
                $constraintExists = DB::select("
                    SELECT COUNT(*) as cnt FROM information_schema.table_constraints 
                    WHERE table_name = ? AND constraint_name = ?
                ", [$table, $indexName]);
                
                if ($constraintExists[0]->cnt > 0) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$indexName}");
                }
                
                // Also check if standalone index exists
                $indexExists = DB::select("
                    SELECT COUNT(*) as cnt FROM pg_indexes 
                    WHERE tablename = ? AND indexname = ?
                ", [$table, $indexName]);
                
                if ($indexExists[0]->cnt > 0) {
                    DB::statement("DROP INDEX {$indexName}");
                }
            } elseif ($driver === 'mysql') {
                // Check if index exists first
                $exists = DB::select("
                    SELECT COUNT(*) as cnt FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ? 
                    AND index_name = ?
                ", [$table, $indexName]);

                if ($exists[0]->cnt > 0) {
                    Schema::table($table, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
            } elseif ($driver === 'sqlite') {
                DB::statement("DROP INDEX IF EXISTS {$indexName}");
            }
        } catch (\Exception $e) {
            // Ignore errors - index might not exist
        }
    }

    /**
     * Safely create an index if it doesn't exist
     */
    private function createIndexSafely(string $table, array $columns, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'pgsql') {
                $columnList = implode(', ', $columns);
                DB::statement("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} ({$columnList})");
            } elseif ($driver === 'mysql') {
                // Check if index exists
                $exists = DB::select("
                    SELECT COUNT(*) as cnt FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ? 
                    AND index_name = ?
                ", [$table, $indexName]);

                if ($exists[0]->cnt == 0) {
                    Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                        $table->index($columns, $indexName);
                    });
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Could not create index {$indexName}", [
                'error' => $e->getMessage()
            ]);
        }
    }
};
