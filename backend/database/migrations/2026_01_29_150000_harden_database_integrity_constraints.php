<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * DATABASE INTEGRITY HARDENING MIGRATION
 * 
 * This migration enforces strict database-level constraints to prevent:
 * 1. Duplicate student attendance per schedule per day
 * 2. Duplicate teacher attendance per day
 * 3. QR nonce replay attacks
 * 4. Device sharing between teachers
 * 
 * All rules are enforced at DATABASE LEVEL for maximum security.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ================================================================
        // 1. STUDENT ATTENDANCE TABLE HARDENING
        // ================================================================
        Schema::table('attendances', function (Blueprint $table) {
            // Check if soft deletes column exists, add unique constraint accordingly
            // For tables with soft deletes, we need partial unique index (PostgreSQL)
            // or handle in application layer + unique without deleted_at
        });
        
        // Drop existing unique constraint if it exists (outside Schema closure for better control)
        $this->dropIndexIfExists('attendances', 'unique_attendance_per_schedule');
        $this->dropIndexIfExists('attendances', 'attendances_schedule_id_student_id_attendance_date_unique');
        
        Schema::table('attendances', function (Blueprint $table) {
            // Add stricter unique: student can only have ONE attendance per schedule per day
            // This prevents any duplicate regardless of soft delete status
            if (!$this->indexExists('attendances', 'uk_attendance_student_schedule_date')) {
                $table->unique(
                    ['student_id', 'schedule_id', 'attendance_date'],
                    'uk_attendance_student_schedule_date'
                );
            }
            
            // Additional index for recorded_by queries
            if (!$this->indexExists('attendances', 'idx_attendance_recorded_by')) {
                $table->index('recorded_by', 'idx_attendance_recorded_by');
            }
            
            // Index for class_id if it exists
            if (Schema::hasColumn('attendances', 'class_id')) {
                if (!$this->indexExists('attendances', 'idx_attendance_class')) {
                    $table->index('class_id', 'idx_attendance_class');
                }
            }
        });

        // ================================================================
        // 2. TEACHER ATTENDANCE TABLE HARDENING
        // ================================================================
        if (Schema::hasTable('teacher_attendances')) {
            Schema::table('teacher_attendances', function (Blueprint $table) {
                // Ensure unique constraint exists (already in create migration, but verify)
                // Add additional indexes for reporting
                if (!$this->indexExists('teacher_attendances', 'idx_teacher_att_status')) {
                    $table->index(['status', 'attendance_date'], 'idx_teacher_att_status');
                }
                
                if (!$this->indexExists('teacher_attendances', 'idx_teacher_att_checkin')) {
                    $table->index('check_in_time', 'idx_teacher_att_checkin');
                }
            });
        }

        // ================================================================
        // 3. QR NONCE TABLE HARDENING
        // ================================================================
        if (Schema::hasTable('qr_nonces')) {
            Schema::table('qr_nonces', function (Blueprint $table) {
                // Add global unique on nonce (regardless of school_id)
                // This prevents nonce reuse across schools
                try {
                    $table->dropUnique(['school_id', 'nonce']);
                } catch (\Exception $e) {
                    // May not exist
                }
                
                // Global unique nonce
                if (!$this->indexExists('qr_nonces', 'uk_qr_nonce_global')) {
                    $table->unique('nonce', 'uk_qr_nonce_global');
                }
                
                // Index for cleanup queries
                if (!$this->indexExists('qr_nonces', 'idx_qr_nonce_student')) {
                    $table->index('student_id', 'idx_qr_nonce_student');
                }
            });
        }

        // ================================================================
        // 4. TEACHER DEVICES HARDENING
        // ================================================================
        if (Schema::hasTable('teacher_devices')) {
            Schema::table('teacher_devices', function (Blueprint $table) {
                // Add global unique on device_id
                // One physical device can only belong to ONE teacher
                if (!$this->indexExists('teacher_devices', 'uk_teacher_device_global')) {
                    $table->unique('device_id', 'uk_teacher_device_global');
                }
                
                // Index for approval status
                if (!$this->indexExists('teacher_devices', 'idx_teacher_device_approved')) {
                    $table->index(['is_approved', 'revoked_at'], 'idx_teacher_device_approved');
                }
                
                // Index for last used queries
                if (!$this->indexExists('teacher_devices', 'idx_teacher_device_last_used')) {
                    $table->index('last_used_at', 'idx_teacher_device_last_used');
                }
            });
        }

        // ================================================================
        // 5. ATTENDANCE FLAGS TABLE HARDENING
        // ================================================================
        if (Schema::hasTable('attendance_flags')) {
            Schema::table('attendance_flags', function (Blueprint $table) {
                // Index for admin dashboard queries
                if (!$this->indexExists('attendance_flags', 'idx_att_flag_type_severity')) {
                    $table->index(['flag_type', 'severity'], 'idx_att_flag_type_severity');
                }
                
                if (!$this->indexExists('attendance_flags', 'idx_att_flag_created')) {
                    $table->index('created_at', 'idx_att_flag_created');
                }
            });
        }

        // ================================================================
        // 6. TEACHER ATTENDANCE ANOMALIES HARDENING
        // ================================================================
        if (Schema::hasTable('teacher_attendance_anomalies')) {
            Schema::table('teacher_attendance_anomalies', function (Blueprint $table) {
                if (!$this->indexExists('teacher_attendance_anomalies', 'idx_teacher_anomaly_type')) {
                    $table->index(['anomaly_type', 'severity'], 'idx_teacher_anomaly_type');
                }
                
                if (!$this->indexExists('teacher_attendance_anomalies', 'idx_teacher_anomaly_reviewed')) {
                    $table->index('is_reviewed', 'idx_teacher_anomaly_reviewed');
                }
            });
        }

        // ================================================================
        // 7. ADD CHECK CONSTRAINTS (PostgreSQL only)
        // ================================================================
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Ensure attendance_date is not in the future
            DB::statement('
                ALTER TABLE attendances 
                ADD CONSTRAINT chk_attendance_date_not_future 
                CHECK (attendance_date <= CURRENT_DATE)
            ');
            
            // Ensure check_in_time <= check_out_time
            DB::statement('
                ALTER TABLE attendances 
                ADD CONSTRAINT chk_checkin_before_checkout 
                CHECK (check_out_time IS NULL OR check_in_time <= check_out_time)
            ');
            
            if (Schema::hasTable('teacher_attendances')) {
                DB::statement('
                    ALTER TABLE teacher_attendances 
                    ADD CONSTRAINT chk_teacher_att_date_not_future 
                    CHECK (attendance_date <= CURRENT_DATE)
                ');
                
                DB::statement('
                    ALTER TABLE teacher_attendances 
                    ADD CONSTRAINT chk_teacher_checkin_checkout 
                    CHECK (check_out_time IS NULL OR check_in_time <= check_out_time)
                ');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove check constraints (PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS chk_attendance_date_not_future');
            DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS chk_checkin_before_checkout');
            
            if (Schema::hasTable('teacher_attendances')) {
                DB::statement('ALTER TABLE teacher_attendances DROP CONSTRAINT IF EXISTS chk_teacher_att_date_not_future');
                DB::statement('ALTER TABLE teacher_attendances DROP CONSTRAINT IF EXISTS chk_teacher_checkin_checkout');
            }
        }

        // Remove indexes and constraints
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('uk_attendance_student_schedule_date');
            $table->dropIndex('idx_attendance_recorded_by');
        });

        if (Schema::hasTable('qr_nonces')) {
            Schema::table('qr_nonces', function (Blueprint $table) {
                $table->dropUnique('uk_qr_nonce_global');
                $table->dropIndex('idx_qr_nonce_student');
            });
        }

        if (Schema::hasTable('teacher_devices')) {
            Schema::table('teacher_devices', function (Blueprint $table) {
                $table->dropUnique('uk_teacher_device_global');
                $table->dropIndex('idx_teacher_device_approved');
                $table->dropIndex('idx_teacher_device_last_used');
            });
        }

        if (Schema::hasTable('attendance_flags')) {
            Schema::table('attendance_flags', function (Blueprint $table) {
                $table->dropIndex('idx_att_flag_type_severity');
                $table->dropIndex('idx_att_flag_created');
            });
        }

        if (Schema::hasTable('teacher_attendance_anomalies')) {
            Schema::table('teacher_attendance_anomalies', function (Blueprint $table) {
                $table->dropIndex('idx_teacher_anomaly_type');
                $table->dropIndex('idx_teacher_anomaly_reviewed');
            });
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        
        if ($driver === 'pgsql') {
            $result = DB::select("
                SELECT 1 FROM pg_indexes 
                WHERE tablename = ? AND indexname = ?
            ", [$table, $indexName]);
            return count($result) > 0;
        }
        
        if ($driver === 'mysql') {
            $result = DB::select("
                SHOW INDEX FROM {$table} WHERE Key_name = ?
            ", [$indexName]);
            return count($result) > 0;
        }
        
        // SQLite - check sqlite_master
        if ($driver === 'sqlite') {
            $result = DB::select("
                SELECT 1 FROM sqlite_master 
                WHERE type = 'index' AND name = ?
            ", [$indexName]);
            return count($result) > 0;
        }
        
        return false;
    }

    /**
     * Drop an index if it exists (safe drop)
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $driver = DB::connection()->getDriverName();
        
        if (!$this->indexExists($table, $indexName)) {
            return;
        }
        
        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$indexName}");
        } elseif ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }
    }
};
