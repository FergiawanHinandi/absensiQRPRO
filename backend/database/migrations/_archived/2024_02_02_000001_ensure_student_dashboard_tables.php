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
        // CONFLICT RESOLUTION:
        // These tables are created by newer 2026 migrations.
        // We disable them here to avoid "table already exists" errors during testing/migration.

        /*
        // Ensure attendance_logs table exists
        if (!Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('schedule_id')->nullable()->constrained('schedules')->onDelete('set null');
                $table->enum('status', ['present', 'late', 'absent', 'sick', 'permission'])->default('present');
                $table->timestamp('scanned_at')->nullable();
                $table->json('location_data')->nullable();
                $table->string('device_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['student_id', 'created_at']);
                $table->index(['schedule_id', 'created_at']);
            });
        }

        // Ensure schedules table exists
        if (!Schema::hasTable('schedules')) {
            Schema::create('schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
                $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
                $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->onDelete('set null');
                $table->tinyInteger('day_of_week'); // 0=Sunday, 1=Monday, etc.
                $table->time('start_time');
                $table->time('end_time');
                $table->string('room')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['class_id', 'day_of_week']);
                $table->index(['teacher_id', 'day_of_week']);
            });
        }

        // Ensure classes table exists
        if (!Schema::hasTable('classes')) {
            Schema::create('classes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->foreignId('grade_id')->constrained('grades')->onDelete('cascade');
                $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
                $table->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->onDelete('set null');
                $table->integer('capacity')->default(36);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['school_id', 'grade_id']);
            });
        }

        // Ensure subjects table exists
        if (!Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->integer('grade_level')->nullable();
                $table->enum('school_level', ['SD', 'SMP', 'SMA', 'SMK']);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Ensure grades table exists
        if (!Schema::hasTable('grades')) {
            Schema::create('grades', function (Blueprint $table) {
                $table->id();
                $table->string('name'); // e.g., "Kelas X", "Kelas XI"
                $table->integer('level'); // 10, 11, 12
                $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Ensure academic_years table exists
        if (!Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function (Blueprint $table) {
                $table->id();
                $table->string('name'); // e.g., "2024/2025"
                $table->date('start_date');
                $table->date('end_date');
                $table->boolean('is_active')->default(false);
                $table->timestamps();
            });
        }
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('attendance_logs');
        // Schema::dropIfExists('schedules');
        // Schema::dropIfExists('classes');
        // Schema::dropIfExists('subjects');
        // Schema::dropIfExists('grades');
        // Schema::dropIfExists('academic_years');
    }
};
