<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add state machine audit fields to attendance_logs table
     * for comprehensive tracking of all state transitions.
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // State machine transition fields
            if (!Schema::hasColumn('attendance_logs', 'from_state')) {
                $table->string('from_state', 50)->nullable()->after('new_status')
                    ->comment('Previous state in state machine');
            }
            
            if (!Schema::hasColumn('attendance_logs', 'to_state')) {
                $table->string('to_state', 50)->nullable()->after('from_state')
                    ->comment('New state in state machine');
            }
            
            if (!Schema::hasColumn('attendance_logs', 'performed_by')) {
                $table->foreignId('performed_by')->nullable()->after('to_state')
                    ->constrained('users')->onDelete('set null')
                    ->comment('User who performed the state transition');
            }
            
            if (!Schema::hasColumn('attendance_logs', 'reason')) {
                $table->text('reason')->nullable()->after('performed_by')
                    ->comment('Reason for state transition');
            }
            
            if (!Schema::hasColumn('attendance_logs', 'changes')) {
                $table->json('changes')->nullable()->after('reason')
                    ->comment('Additional data changes during transition');
            }
            
            // Add index for state transition queries
            $table->index(['attendance_id', 'from_state', 'to_state'], 'idx_attendance_logs_state_transitions');
            $table->index(['performed_by', 'created_at'], 'idx_attendance_logs_performer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_logs_state_transitions');
            $table->dropIndex('idx_attendance_logs_performer');
            
            $table->dropColumn([
                'from_state',
                'to_state',
                'performed_by',
                'reason',
                'changes',
            ]);
        });
    }
};
