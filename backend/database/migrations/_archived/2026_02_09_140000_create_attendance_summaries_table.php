<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('daily_attendance_summaries')) {
            return;
        }

        Schema::create('daily_attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            
            // Counters (unsigned for minor optimization)
            $table->unsignedInteger('total_present')->default(0);
            $table->unsignedInteger('total_late')->default(0);
            $table->unsignedInteger('total_absent')->default(0); // Alpha
            $table->unsignedInteger('total_permission')->default(0); // Izin
            $table->unsignedInteger('total_sick')->default(0); // Sakit
            
            $table->timestamps();

            // CRITICAL: Composite Index for instant dashboard lookup
            // Ensures unique summary per school per day
            $table->unique(['school_id', 'date']);
            
            // Optimization for date range reports
            $table->index(['school_id', 'date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('daily_attendance_summaries');
    }
};
