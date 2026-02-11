<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * READ MODEL for CQRS Architecture
     * This table stores pre-aggregated daily attendance summaries
     * to avoid heavy queries on the main attendances table.
     */
    public function up(): void
    {
        Schema::create('attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            
            // Composite key fields
            $table->unsignedBigInteger('school_id');
            $table->date('attendance_date');
            
            // Optional breakdown by class
            $table->unsignedBigInteger('class_id')->nullable();
            
            // Aggregated counts
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('total_present')->default(0);
            $table->unsignedInteger('total_late')->default(0);
            $table->unsignedInteger('total_absent')->default(0);
            $table->unsignedInteger('total_excused')->default(0);
            
            // Calculated metrics
            $table->decimal('attendance_rate', 5, 2)->default(0); // Percentage
            
            // Metadata
            $table->timestamp('last_updated_at')->useCurrent();
            $table->timestamps();
            
            // PRIMARY KEY: Composite (school_id, attendance_date, class_id)
            // This ensures uniqueness and optimal query performance
            $table->unique(['school_id', 'attendance_date', 'class_id'], 'unique_school_date_class');
            
            // INDEXES for common query patterns
            $table->index(['school_id', 'attendance_date'], 'idx_daily_summary_school_date');
            $table->index(['school_id', 'class_id', 'attendance_date'], 'idx_daily_summary_school_class_date');
            $table->index('attendance_date', 'idx_daily_summary_date');
        });
        
        // Note: Add index on attendances table manually if needed:
        // CREATE INDEX IF NOT EXISTS idx_school_date_status ON attendances (school_id, attendance_date, status);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_summaries');
    }
};
