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
        // 1. Schedules Table Index
        Schema::table('schedules', function (Blueprint $table) {
            if (! $this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->index(
                    ['teacher_id', 'school_id', 'day_of_week', 'is_active'],
                    'idx_schedule_lookup'
                );
            }
        });

        // 2. Attendances Table Index
        Schema::table('attendances', function (Blueprint $table) {
            if (! $this->indexExists('attendances', 'idx_attendance_report')) {
                $table->index(
                    ['school_id', 'attendance_date'],
                    'idx_attendance_report'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if ($this->indexExists('schedules', 'idx_schedule_lookup')) {
                $table->dropIndex('idx_schedule_lookup');
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if ($this->indexExists('attendances', 'idx_attendance_report')) {
                $table->dropIndex('idx_attendance_report');
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
            return DB::selectOne('
                SELECT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE tablename = ? AND indexname = ?
                ) as exists
            ', [$table, $indexName])->exists ?? false;
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
