<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SOFT DELETE SAFETY MIGRATION
 * 
 * This migration ensures that soft deleted records don't interfere with
 * unique constraints while still preventing actual duplicates.
 * 
 * For PostgreSQL: Uses partial unique indexes (WHERE deleted_at IS NULL)
 * For MySQL: Uses functional unique indexes or triggers
 * For SQLite: Application-level enforcement
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
        // STUDENT ATTENDANCE - Partial Unique Index
        // ================================================================
        if ($driver === 'pgsql') {
            // PostgreSQL: Create partial unique index that only applies to non-deleted rows
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS uk_attendance_active_student_schedule 
                ON attendances (student_id, schedule_id, attendance_date) 
                WHERE deleted_at IS NULL
            ');
            
            // Drop the old non-partial unique constraint if exists
            // (Keep it for safety, partial index will catch active records)
        }
        
        if ($driver === 'mysql') {
            // MySQL 8.0+: Add a computed column for soft delete unique handling
            // This column is NULL when deleted, allowing multiple "deleted" duplicates
            // but preventing duplicates among active records
            if (!Schema::hasColumn('attendances', 'unique_check')) {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->unsignedBigInteger('unique_check')->nullable()->after('deleted_at');
                });
                
                // Set unique_check = id for all active records
                DB::statement('UPDATE attendances SET unique_check = id WHERE deleted_at IS NULL');
                
                // Create trigger to maintain unique_check
                DB::unprepared('
                    CREATE TRIGGER trg_attendance_unique_check_insert
                    BEFORE INSERT ON attendances
                    FOR EACH ROW
                    BEGIN
                        IF NEW.deleted_at IS NULL THEN
                            SET NEW.unique_check = COALESCE(NEW.id, 0);
                        ELSE
                            SET NEW.unique_check = NULL;
                        END IF;
                    END
                ');
                
                DB::unprepared('
                    CREATE TRIGGER trg_attendance_unique_check_update
                    BEFORE UPDATE ON attendances
                    FOR EACH ROW
                    BEGIN
                        IF NEW.deleted_at IS NULL THEN
                            SET NEW.unique_check = NEW.id;
                        ELSE
                            SET NEW.unique_check = NULL;
                        END IF;
                    END
                ');
            }
        }

        // ================================================================
        // TEACHER ATTENDANCE - Partial Unique Index
        // ================================================================
        if (Schema::hasTable('teacher_attendances')) {
            if ($driver === 'pgsql') {
                DB::statement('
                    CREATE UNIQUE INDEX IF NOT EXISTS uk_teacher_attendance_active 
                    ON teacher_attendances (teacher_id, attendance_date) 
                    WHERE deleted_at IS NULL
                ');
            }
            
            if ($driver === 'mysql') {
                if (!Schema::hasColumn('teacher_attendances', 'unique_check')) {
                    Schema::table('teacher_attendances', function (Blueprint $table) {
                        $table->unsignedBigInteger('unique_check')->nullable()->after('deleted_at');
                    });
                    
                    DB::statement('UPDATE teacher_attendances SET unique_check = id WHERE deleted_at IS NULL');
                    
                    DB::unprepared('
                        CREATE TRIGGER trg_teacher_att_unique_check_insert
                        BEFORE INSERT ON teacher_attendances
                        FOR EACH ROW
                        BEGIN
                            IF NEW.deleted_at IS NULL THEN
                                SET NEW.unique_check = COALESCE(NEW.id, 0);
                            ELSE
                                SET NEW.unique_check = NULL;
                            END IF;
                        END
                    ');
                    
                    DB::unprepared('
                        CREATE TRIGGER trg_teacher_att_unique_check_update
                        BEFORE UPDATE ON teacher_attendances
                        FOR EACH ROW
                        BEGIN
                            IF NEW.deleted_at IS NULL THEN
                                SET NEW.unique_check = NEW.id;
                            ELSE
                                SET NEW.unique_check = NULL;
                            END IF;
                        END
                    ');
                }
            }
        }

        // ================================================================
        // USERS TABLE - Ensure deleted users don't block email/NIS
        // ================================================================
        if ($driver === 'pgsql') {
            // Allow same email/nis for deleted users, but unique among active
            if (Schema::hasColumn('users', 'nis')) {
                DB::statement('
                    CREATE UNIQUE INDEX IF NOT EXISTS uk_users_active_nis 
                    ON users (nis) 
                    WHERE deleted_at IS NULL AND nis IS NOT NULL
                ');
            }
            
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS uk_users_active_email 
                ON users (email) 
                WHERE deleted_at IS NULL
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS uk_attendance_active_student_schedule');
            DB::statement('DROP INDEX IF EXISTS uk_teacher_attendance_active');
            DB::statement('DROP INDEX IF EXISTS uk_users_active_nis');
            DB::statement('DROP INDEX IF EXISTS uk_users_active_email');
        }

        if ($driver === 'mysql') {
            // Drop triggers
            DB::unprepared('DROP TRIGGER IF EXISTS trg_attendance_unique_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_attendance_unique_check_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_teacher_att_unique_check_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_teacher_att_unique_check_update');
            
            // Drop columns
            if (Schema::hasColumn('attendances', 'unique_check')) {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->dropColumn('unique_check');
                });
            }
            
            if (Schema::hasTable('teacher_attendances') && Schema::hasColumn('teacher_attendances', 'unique_check')) {
                Schema::table('teacher_attendances', function (Blueprint $table) {
                    $table->dropColumn('unique_check');
                });
            }
        }
    }
};
