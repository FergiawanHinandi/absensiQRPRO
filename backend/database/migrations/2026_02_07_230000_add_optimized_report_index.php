<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Composite Index for Filtering by School + Status + Date Range
            // ORDER MATTERS: Equality (school, status) -> Range (date)
            // Used for: "Show all PRESENT students this Month"
            $table->index(['school_id', 'status', 'attendance_date'], 'idx_attendance_report_composite');
            
            // Composite Index for Student History
            // Used for: "Show student's attendance history"
            // Ensure this exists if filtered by status often
            // $table->index(['student_id', 'attendance_date', 'status'], 'idx_student_history_optimized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_report_composite');
        });
    }
};
