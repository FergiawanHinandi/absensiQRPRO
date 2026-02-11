<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_summary_views')) {
            return;
        }

        Schema::create('attendance_summary_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable();
            $table->date('attendance_date');
            $table->integer('total_students')->default(0);
            $table->integer('present_count')->default(0);
            $table->integer('late_count')->default(0);
            $table->integer('absent_count')->default(0);
            $table->integer('excused_count')->default(0);
            $table->decimal('attendance_rate', 5, 2)->default(0);
            $table->timestamps();

            // Unique constraint for upsert operations
            $table->unique(
                ['school_id', 'class_id', 'schedule_id', 'attendance_date'],
                'attendance_summary_unique'
            );

            // Index for school-level dashboard queries
            $table->index(['school_id', 'attendance_date'], 'idx_view_summary_school_date');

            // Index for class-level queries
            $table->index(['class_id', 'attendance_date'], 'idx_summary_views_class_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_summary_views');
    }
};
