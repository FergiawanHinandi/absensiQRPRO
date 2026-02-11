<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * ✅ SECURITY AUDIT FIX: Dashboard Optimization (HIGH PRIORITY)
     * 
     * Creates daily attendance summary tables to prevent slow count() queries
     * on millions of attendance records. Dashboard will query this summary
     * table instead of raw attendance data.
     */
    public function up(): void
    {
        if (Schema::hasTable('daily_attendance_summaries')) {
            return;
        }

        Schema::create('daily_attendance_summaries', function (Blueprint $table) {
            $table->id();
            
            // Identifiers
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
            $table->date('summary_date');
            
            // Counters
            $table->integer('total_students')->default(0);
            $table->integer('present_count')->default(0);
            $table->integer('late_count')->default(0);
            $table->integer('absent_count')->default(0);
            $table->integer('excused_count')->default(0);
            $table->integer('sick_count')->default(0);
            
            // Calculated metrics
            $table->decimal('attendance_rate', 5, 2)->default(0); // Percentage
            $table->decimal('late_rate', 5, 2)->default(0); // Percentage
            
            // Metadata
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
            
            // Indexes for fast lookups
            $table->unique(['school_id', 'class_id', 'summary_date'], 'school_class_date_unique');
            $table->index(['school_id', 'summary_date']);
            $table->index('summary_date');
        });

        // Add comment for documentation
        DB::statement("COMMENT ON TABLE daily_attendance_summaries IS 'Pre-calculated daily attendance statistics for fast dashboard queries'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_attendance_summaries');
    }
};
