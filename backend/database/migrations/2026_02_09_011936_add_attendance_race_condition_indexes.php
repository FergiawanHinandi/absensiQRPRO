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
     * Adds optimized composite index for race condition prevention.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // Index 1: (schedule_id, school_id, attendance_date) for concurrency lookup
        $this->createIndexSafely($driver, 'attendances', 
            ['schedule_id', 'school_id', 'attendance_date'], 
            'idx_attendance_lookup'
        );

        // Index 2: (school_id, status, attendance_date) for status queries
        $this->createIndexSafely($driver, 'attendances', 
            ['school_id', 'status', 'attendance_date'], 
            'idx_attendance_school_status'
        );

        // Index 3: PostgreSQL partial index for checked-in records
        if ($driver === 'pgsql') {
            $exists = DB::selectOne("
                SELECT 1 FROM pg_indexes 
                WHERE schemaname = current_schema() 
                AND indexname = 'idx_attendance_concurrent_check'
            ");

            if (!$exists) {
                // Note: CONCURRENTLY cannot be used in transactions, so we use regular CREATE INDEX
                DB::statement("
                    CREATE INDEX idx_attendance_concurrent_check
                    ON attendances (student_id, schedule_id, attendance_date)
                    WHERE check_in_time IS NOT NULL
                ");
            }
        }
    }

    /**
     * Safely create index if not exists
     */
    private function createIndexSafely(string $driver, string $table, array $columns, string $indexName): void
    {
        try {
            if ($driver === 'pgsql') {
                $exists = DB::selectOne("
                    SELECT 1 FROM pg_indexes 
                    WHERE schemaname = current_schema() 
                    AND indexname = ?
                ", [$indexName]);

                if (!$exists) {
                    $columnList = implode(', ', $columns);
                    DB::statement("CREATE INDEX \"{$indexName}\" ON \"{$table}\" ({$columnList})");
                }
            } else {
                // MySQL/SQLite
                Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                    $t->index($columns, $indexName);
                });
            }
        } catch (\Exception $e) {
            // Index might already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_lookup');
        $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_school_status');
        $this->dropIndexSafely($driver, 'attendances', 'idx_attendance_concurrent_check');
    }

    /**
     * Safely drop index if exists
     */
    private function dropIndexSafely(string $driver, string $table, string $indexName): void
    {
        try {
            if ($driver === 'pgsql') {
                $exists = DB::selectOne("
                    SELECT 1 FROM pg_indexes 
                    WHERE schemaname = current_schema() 
                    AND indexname = ?
                ", [$indexName]);

                if ($exists) {
                    DB::statement("DROP INDEX \"{$indexName}\"");
                }
            } else {
                Schema::table($table, function (Blueprint $t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            }
        } catch (\Exception $e) {
            // Index might not exist
        }
    }
};
