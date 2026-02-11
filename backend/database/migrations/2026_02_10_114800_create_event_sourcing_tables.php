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
        // 1. Event Store Table
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_id')->index()->comment('Attendance aggregate ID');
            $table->string('event_type', 100)->index()->comment('Event class name');
            $table->json('payload')->comment('Event data');
            $table->json('metadata')->nullable()->comment('User, IP, timestamp, etc');
            $table->unsignedInteger('version')->comment('Aggregate version (optimistic locking)');
            $table->timestamp('occurred_at')->index()->comment('When event occurred');
            $table->timestamps();

            // Unique constraint: one version per aggregate
            $table->unique(['aggregate_id', 'version'], 'unique_aggregate_version');

            // Indexes for querying
            $table->index(['aggregate_id', 'version'], 'idx_aggregate_version');
        });

        // 2. Snapshot Table
        Schema::create('attendance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_id')->unique()->comment('Attendance aggregate ID');
            $table->json('state')->comment('Aggregate state at this version');
            $table->unsignedInteger('version')->comment('Version of this snapshot');
            $table->timestamp('created_at')->index();

            // Index for quick lookup
            $table->index(['aggregate_id', 'version'], 'idx_snapshot_lookup');
        });

        // 3. Read Model Table
        Schema::create('attendance_read', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('Same as aggregate_id');
            $table->foreignId('school_id')->index();
            $table->foreignId('student_id')->index();
            $table->foreignId('schedule_id')->index();
            $table->date('attendance_date')->index();
            $table->enum('status', ['present', 'late', 'absent', 'excused']);
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->string('correction_reason')->nullable();
            $table->enum('correction_status', ['pending', 'approved', 'rejected'])->nullable();
            $table->unsignedInteger('version')->comment('Current version from event store');
            $table->timestamps();

            // Unique constraint (same as original)
            $table->unique(
                ['schedule_id', 'student_id', 'attendance_date'],
                'unique_attendance_read_per_day'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_read');
        Schema::dropIfExists('attendance_snapshots');
        Schema::dropIfExists('attendance_events');
    }
};
