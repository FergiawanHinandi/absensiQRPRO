<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            
            // Rolling 14-day averages
            $table->decimal('avg_scans_per_day', 8, 2)->default(0);
            $table->decimal('avg_successful_scans', 8, 2)->default(0);
            $table->decimal('avg_failed_ratio', 5, 4)->default(0); // 0.0000 - 1.0000
            $table->decimal('avg_outside_radius_attempts', 8, 2)->default(0);
            $table->decimal('avg_device_mismatch_attempts', 8, 2)->default(0);
            $table->decimal('avg_schedule_mismatch_attempts', 8, 2)->default(0);
            $table->decimal('avg_scan_interval_seconds', 10, 2)->nullable();
            
            // Standard deviations for Z-score calculations
            $table->decimal('stddev_scans', 8, 2)->default(0);
            $table->decimal('stddev_failed_ratio', 5, 4)->default(0);
            $table->decimal('stddev_scan_interval', 10, 2)->default(0);
            
            // Baseline period info
            $table->unsignedInteger('days_in_baseline')->default(0);
            $table->date('baseline_start_date')->nullable();
            $table->date('baseline_end_date')->nullable();
            
            // Current risk assessment
            $table->enum('current_risk_level', ['normal', 'suspicious', 'high', 'critical'])->default('normal');
            $table->unsignedTinyInteger('current_risk_score')->default(0);
            $table->json('current_risk_factors')->nullable();
            $table->timestamp('last_risk_assessment')->nullable();
            
            // Account flags
            $table->boolean('requires_device_reverification')->default(false);
            $table->boolean('flagged_for_review')->default(false);
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users');
            
            $table->timestamps();

            // One baseline per user
            $table->unique('user_id', 'unique_user_baseline');
            
            // Indexes
            $table->index(['school_id', 'current_risk_level'], 'idx_baseline_school_risk');
            $table->index('flagged_for_review', 'idx_baseline_flagged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_baselines');
    }
};
