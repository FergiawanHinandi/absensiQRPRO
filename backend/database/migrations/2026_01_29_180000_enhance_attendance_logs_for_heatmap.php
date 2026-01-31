<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhance attendance_logs table for heatmap feature
     */
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // Add school_id for faster filtering
            if (!Schema::hasColumn('attendance_logs', 'school_id')) {
                $table->foreignId('school_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            }

            // Add teacher_id for direct teacher filtering (user_id might be student)
            if (!Schema::hasColumn('attendance_logs', 'teacher_id')) {
                $table->foreignId('teacher_id')->nullable()->after('school_id')->constrained('users')->onDelete('cascade');
            }

            // Add students_scanned count for this scan session
            if (!Schema::hasColumn('attendance_logs', 'students_scanned')) {
                $table->integer('students_scanned')->default(1)->after('location_accuracy');
            }

            // Composite index for heatmap queries
            $table->index(['school_id', 'created_at'], 'idx_attendance_logs_school_time');
            $table->index(['teacher_id', 'created_at'], 'idx_attendance_logs_teacher_time');
            $table->index(['school_id', 'teacher_id', 'created_at'], 'idx_attendance_logs_heatmap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_logs_school_time');
            $table->dropIndex('idx_attendance_logs_teacher_time');
            $table->dropIndex('idx_attendance_logs_heatmap');
            
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropColumn('students_scanned');
        });
    }
};
