<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->date('date');

            // Scan metrics
            $table->unsignedInteger('total_scans')->default(0);
            $table->unsignedInteger('successful_scans')->default(0);
            $table->unsignedInteger('failed_scans')->default(0);

            // Violation attempts
            $table->unsignedInteger('outside_radius_attempts')->default(0);
            $table->unsignedInteger('device_mismatch_attempts')->default(0);
            $table->unsignedInteger('schedule_mismatch_attempts')->default(0);
            $table->unsignedInteger('qr_replay_attempts')->default(0);

            // Timing metrics
            $table->unsignedInteger('avg_scan_interval_seconds')->nullable();
            $table->unsignedInteger('min_scan_interval_seconds')->nullable();
            $table->unsignedInteger('max_scan_interval_seconds')->nullable();

            // Location metrics
            $table->decimal('avg_distance_from_school', 10, 2)->nullable();
            $table->decimal('max_distance_from_school', 10, 2)->nullable();

            // Session info
            $table->unsignedInteger('unique_devices_used')->default(1);
            $table->time('first_scan_time')->nullable();
            $table->time('last_scan_time')->nullable();

            $table->timestamps();

            // Unique constraint: one record per user per day
            $table->unique(['user_id', 'date'], 'unique_user_daily_metrics');

            // Indexes for queries
            $table->index(['school_id', 'date'], 'idx_behavior_school_date');
            $table->index(['date', 'failed_scans'], 'idx_behavior_date_failed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_metrics_daily');
    }
};
