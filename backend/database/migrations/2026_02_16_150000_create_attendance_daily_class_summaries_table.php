<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates a daily class-level summary table for dashboard optimization.
     * This table pre-aggregates attendance counts per class per day to avoid
     * expensive joins and aggregations in dashboard queries.
     * 
     * Performance Target: Dashboard queries < 50ms (from 500-2000ms)
     */
    public function up(): void
    {
        if (Schema::hasTable('attendance_daily_class_summaries')) {
            return;
        }

        Schema::create('attendance_daily_class_summaries', function (Blueprint $table) {
            $table->id();

            
            // Multi-tenant and class identification
            $table->foreignId('school_id')
                ->constrained('schools')
                ->onDelete('cascade')
                ->comment('Multi-tenant isolation');
            
            $table->foreignId('class_id')
                ->constrained('classes')
                ->onDelete('cascade')
                ->comment('Class identifier');
            
            $table->date('attendance_date')
                ->comment('Date of attendance (not datetime)');
            
            // Student counts by status
            $table->unsignedInteger('total_students')
                ->default(0)
                ->comment('Total active students in class on this date');
            
            $table->unsignedInteger('present_count')
                ->default(0)
                ->comment('Students marked present (checked_in, checked_out, approved)');
            
            $table->unsignedInteger('late_count')
                ->default(0)
                ->comment('Students marked late');
            
            $table->unsignedInteger('absent_count')
                ->default(0)
                ->comment('Students marked absent (explicit absence)');
            
            $table->unsignedInteger('sick_count')
                ->default(0)
                ->comment('Students marked sick');
            
            $table->unsignedInteger('permit_count')
                ->default(0)
                ->comment('Students with permission/permit');
            
            $table->unsignedInteger('excused_count')
                ->default(0)
                ->comment('Students excused');
            
            $table->unsignedInteger('alpha_count')
                ->default(0)
                ->comment('Students with no attendance record (no-show)');
            
            // Metadata
            $table->timestamp('last_updated_at')
                ->nullable()
                ->comment('Timestamp of last summary update');
            
            $table->timestamps();
            
            // Indexes for fast dashboard queries
            $table->index(['school_id', 'attendance_date'], 'idx_daily_class_summary_school_date');
            $table->index(['class_id', 'attendance_date'], 'idx_daily_class_summary_class_date');
            $table->unique(
                ['school_id', 'class_id', 'attendance_date'],
                'unique_summary_per_day'
            );
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_class_summaries');
    }
};

