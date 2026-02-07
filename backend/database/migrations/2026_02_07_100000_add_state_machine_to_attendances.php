<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add State Machine Support to Attendances Table
 *
 * This migration adds:
 * 1. `state` column for explicit state machine
 * 2. Approval workflow columns
 * 3. Unique constraint on (student_id, attendance_date, attendance_type)
 *
 * STATES: init, checked_in, checked_out, pending_approval, approved, rejected
 *
 * IMPORTANT: This migration preserves existing data by mapping legacy status to new state.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // ─────────────────────────────────────────────────────────────
            // 1. ADD STATE MACHINE COLUMN
            // ─────────────────────────────────────────────────────────────
            $table->string('state', 20)
                ->default('init')
                ->after('status')
                ->comment('State machine: init|checked_in|checked_out|pending_approval|approved|rejected');

            // ─────────────────────────────────────────────────────────────
            // 2. ADD CHECK-OUT LOCATION FIELDS
            // ─────────────────────────────────────────────────────────────
            $table->decimal('lat_out', 10, 8)->nullable()->after('lng_in');
            $table->decimal('lng_out', 11, 8)->nullable()->after('lat_out');
            $table->string('device_id_out', 64)->nullable()->after('device_id_in');

            // ─────────────────────────────────────────────────────────────
            // 3. ADD APPROVAL WORKFLOW COLUMNS
            // ─────────────────────────────────────────────────────────────
            $table->text('correction_reason')->nullable();
            $table->foreignId('correction_requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('correction_requested_at')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();

            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // ─────────────────────────────────────────────────────────────
            // 4. ADD INDEXES
            // ─────────────────────────────────────────────────────────────
            $table->index('state', 'idx_attendances_state');
            $table->index(['school_id', 'state'], 'idx_attendances_school_state');
            $table->index(['student_id', 'attendance_date', 'state'], 'idx_attendances_student_date_state');
        });

        // ─────────────────────────────────────────────────────────────
        // 5. ADD UNIQUE CONSTRAINT FOR DUPLICATE PREVENTION
        // ─────────────────────────────────────────────────────────────
        // Note: We need separate statement for PostgreSQL compatibility
        Schema::table('attendances', function (Blueprint $table) {
            // Unique constraint: One attendance per student per date per type
            // This prevents duplicate check-ins or check-outs
            $table->unique(
                ['student_id', 'attendance_date', 'attendance_type'],
                'unique_student_date_type'
            );
        });

        // ─────────────────────────────────────────────────────────────
        // 6. MIGRATE EXISTING DATA TO NEW STATE
        // ─────────────────────────────────────────────────────────────
        $this->migrateExistingData();
    }

    /**
     * Migrate existing status values to new state
     */
    private function migrateExistingData(): void
    {
        // Map legacy status to new state
        // Records with check_out_time are CHECKED_OUT
        DB::table('attendances')
            ->whereNotNull('check_out_time')
            ->update(['state' => 'checked_out']);

        // Records with check_in_time but no check_out are CHECKED_IN
        DB::table('attendances')
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->where('state', 'init')
            ->update(['state' => 'checked_in']);

        // Records marked as present/late without times are CHECKED_IN
        DB::table('attendances')
            ->whereIn('status', ['present', 'late'])
            ->where('state', 'init')
            ->update(['state' => 'checked_in']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop unique constraint
            $table->dropUnique('unique_student_date_type');

            // Drop indexes
            $table->dropIndex('idx_attendances_state');
            $table->dropIndex('idx_attendances_school_state');
            $table->dropIndex('idx_attendances_student_date_state');

            // Drop foreign keys
            $table->dropConstrainedForeignId('correction_requested_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');

            // Drop columns
            $table->dropColumn([
                'state',
                'lat_out',
                'lng_out',
                'device_id_out',
                'correction_reason',
                'correction_requested_at',
                'approved_at',
                'approval_notes',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
