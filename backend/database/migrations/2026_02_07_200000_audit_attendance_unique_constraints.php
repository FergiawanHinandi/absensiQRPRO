<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * AUDIT & FIX: Attendance Unique Constraints for Soft Delete Compatibility
 *
 * BUSINESS RULES:
 * 1. A student can have ONLY ONE active attendance per schedule per day per type
 * 2. Soft-deleted records should NOT block new inserts
 *
 * PostgreSQL: Uses partial unique index WHERE deleted_at IS NULL
 */
return new class extends Migration
{
    /**
     * Disable transaction wrapper for PostgreSQL DDL operations
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Log::info('Starting attendance unique constraint audit', ['driver' => $driver]);

        // For PostgreSQL, we need to handle each operation separately
        // to avoid transaction abort issues
        if ($driver === 'pgsql') {
            $this->upPostgres();
        } else {
            $this->upOther($driver);
        }

        Log::info('Completed attendance unique constraint audit');
    }

    /**
     * PostgreSQL-specific migration - handles transaction issues
     */
    private function upPostgres(): void
    {
        // Drop indexes one by one
        $indexesToDrop = [
            'unique_attendance_per_schedule',
            'unique_attendance_with_type',
            'unique_attendance_per_day',
            'attendances_schedule_id_student_id_attendance_date_unique',
            'attendances_student_id_schedule_id_attendance_date_unique',
            'attendances_student_id_attendance_date_attendance_type_unique',
            'uk_attendance_student_schedule_date',
            'uk_attendance_soft_delete_safe',
            'uk_attendance_type_soft_delete_safe',
            'uk_attendance_active_student_schedule',
            'uk_attendance_unique_per_day_type',
        ];

        foreach ($indexesToDrop as $indexName) {
            try {
                DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
                Log::debug("Dropped index: {$indexName}");
            } catch (\Exception $e) {
                Log::debug("Could not drop index {$indexName}: " . $e->getMessage());
            }
        }

        // Check if our target index already exists
        $exists = DB::selectOne("
            SELECT 1 FROM pg_indexes 
            WHERE schemaname = current_schema() 
            AND indexname = 'uk_attendance_active_unique'
        ");

        if (!$exists) {
            // Create the partial unique index
            DB::statement("
                CREATE UNIQUE INDEX uk_attendance_active_unique
                ON attendances (student_id, schedule_id, attendance_date, attendance_type)
                WHERE deleted_at IS NULL
            ");
            Log::info('Created PostgreSQL partial unique index: uk_attendance_active_unique');
        } else {
            Log::info('Index uk_attendance_active_unique already exists');
        }

        // Create performance indexes
        $performanceIndexes = [
            'idx_attendance_school_date_v2' => 'school_id, attendance_date',
            'idx_attendance_student_date_v2' => 'student_id, attendance_date',
            'idx_attendance_deleted_at' => 'deleted_at',
            'idx_attendance_duplicate_check_v2' => 'student_id, schedule_id, attendance_date, attendance_type, deleted_at',
        ];

        foreach ($performanceIndexes as $indexName => $columns) {
            try {
                $indexExists = DB::selectOne("
                    SELECT 1 FROM pg_indexes 
                    WHERE schemaname = current_schema() 
                    AND indexname = ?
                ", [$indexName]);
                
                if (!$indexExists) {
                    DB::statement("CREATE INDEX \"{$indexName}\" ON attendances ({$columns})");
                    Log::debug("Created index: {$indexName}");
                }
            } catch (\Exception $e) {
                Log::debug("Could not create index {$indexName}: " . $e->getMessage());
            }
        }
    }

    /**
     * MySQL/SQLite migration
     */
    private function upOther(string $driver): void
    {
        // Drop conflicting constraints
        $this->dropConflictingConstraints($driver);

        // Create soft-delete-safe unique constraint
        if ($driver === 'mysql') {
            $this->createMysqlConstraint();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteConstraint();
        }

        // Add performance indexes
        $this->addPerformanceIndexes($driver);
    }

    /**
     * Drop conflicting constraints for MySQL/SQLite
     */
    private function dropConflictingConstraints(string $driver): void
    {
        $indexesToDrop = [
            'unique_attendance_per_schedule',
            'unique_attendance_with_type',
            'unique_attendance_per_day',
            'attendances_schedule_id_student_id_attendance_date_unique',
            'attendances_student_id_schedule_id_attendance_date_unique',
            'attendances_student_id_attendance_date_attendance_type_unique',
            'uk_attendance_student_schedule_date',
            'uk_attendance_soft_delete_safe',
            'uk_attendance_type_soft_delete_safe',
            'uk_attendance_active_student_schedule',
            'uk_attendance_active_unique',
            'uk_attendance_unique_per_day_type',
        ];

        foreach ($indexesToDrop as $indexName) {
            $this->dropIndexSafely($driver, 'attendances', $indexName);
        }

        // Drop MySQL generated column if exists
        if ($driver === 'mysql' && Schema::hasColumn('attendances', 'active_unique_key')) {
            try {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->dropColumn('active_unique_key');
                });
            } catch (\Exception $e) {
                Log::debug('Could not drop active_unique_key column', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Safe drop for MySQL/SQLite
     */
    private function dropIndexSafely(string $driver, string $table, string $indexName): void
    {
        try {
            if ($driver === 'mysql') {
                $exists = DB::selectOne("
                    SELECT 1 FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ? 
                    AND index_name = ?
                ", [$table, $indexName]);

                if ($exists) {
                    Schema::table($table, function (Blueprint $t) use ($indexName) {
                        $t->dropIndex($indexName);
                    });
                }
            } elseif ($driver === 'sqlite') {
                DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
            }
        } catch (\Exception $e) {
            Log::debug("Index {$indexName} could not be dropped", ['error' => $e->getMessage()]);
        }
    }

    /**
     * MySQL: Generated column approach
     */
    private function createMysqlConstraint(): void
    {
        if (!Schema::hasColumn('attendances', 'unique_active_key')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->string('unique_active_key', 150)
                    ->nullable()
                    ->storedAs("
                        CASE 
                            WHEN deleted_at IS NULL 
                            THEN CONCAT(
                                student_id, '-', 
                                COALESCE(schedule_id, 0), '-', 
                                attendance_date, '-', 
                                COALESCE(attendance_type, 'in')
                            )
                            ELSE NULL 
                        END
                    ")
                    ->after('deleted_at');
            });

            Schema::table('attendances', function (Blueprint $table) {
                $table->unique('unique_active_key', 'uk_attendance_active_unique');
            });
        }
    }

    /**
     * SQLite: Partial index
     */
    private function createSqliteConstraint(): void
    {
        try {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS uk_attendance_active_unique
                ON attendances (student_id, schedule_id, attendance_date, attendance_type)
                WHERE deleted_at IS NULL
            ");
        } catch (\Exception $e) {
            Log::warning('SQLite partial index failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Add performance indexes for MySQL/SQLite
     */
    private function addPerformanceIndexes(string $driver): void
    {
        $indexes = [
            'idx_attendance_school_date_v2' => ['school_id', 'attendance_date'],
            'idx_attendance_student_date_v2' => ['student_id', 'attendance_date'],
            'idx_attendance_deleted_at' => ['deleted_at'],
            'idx_attendance_duplicate_check_v2' => ['student_id', 'schedule_id', 'attendance_date', 'attendance_type', 'deleted_at'],
        ];

        foreach ($indexes as $indexName => $columns) {
            $this->createIndexSafely($driver, 'attendances', $columns, $indexName);
        }
    }

    /**
     * Safe create index for MySQL/SQLite
     */
    private function createIndexSafely(string $driver, string $table, array $columns, string $indexName): void
    {
        try {
            $columnList = implode(', ', $columns);

            if ($driver === 'mysql') {
                $exists = DB::selectOne("
                    SELECT 1 FROM information_schema.statistics 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ? 
                    AND index_name = ?
                ", [$table, $indexName]);

                if (!$exists) {
                    Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                        $t->index($columns, $indexName);
                    });
                }
            } elseif ($driver === 'sqlite') {
                DB::statement("CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON \"{$table}\" ({$columnList})");
            }
        } catch (\Exception $e) {
            Log::warning("Could not create index {$indexName}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $pdo = DB::connection()->getPdo();
            
            $indexesToDrop = [
                'uk_attendance_active_unique',
                'idx_attendance_school_date_v2',
                'idx_attendance_student_date_v2',
                'idx_attendance_deleted_at',
                'idx_attendance_duplicate_check_v2',
            ];

            foreach ($indexesToDrop as $indexName) {
                try {
                    $pdo->exec("DROP INDEX IF EXISTS \"{$indexName}\"");
                } catch (\PDOException $e) {
                    // Ignore
                }
            }
        } else {
            $this->dropIndexSafely($driver, 'attendances', 'uk_attendance_active_unique');
            $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_school_date_v2');
            $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_student_date_v2');
            $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_deleted_at');
            $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_duplicate_check_v2');

            if ($driver === 'mysql' && Schema::hasColumn('attendances', 'unique_active_key')) {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->dropColumn('unique_active_key');
                });
            }
        }
    }
};
