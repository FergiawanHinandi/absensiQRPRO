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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('schedule_id')->constrained('schedules')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->date('attendance_date');
            $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit', 'excused']);
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->boolean('is_manual')->default(false)->comment('Manual input by teacher');
            $table->text('notes')->nullable();
            $table->string('attachment_url', 255)->nullable()->comment('Surat izin/keterangan');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Indexes & Constraints
            $table->index(['student_id', 'attendance_date'], 'idx_attendances_student');
            $table->index(['schedule_id', 'attendance_date'], 'idx_attendances_schedule');
            $table->index(['school_id', 'attendance_date'], 'idx_attendances_school');
            $table->index(['status', 'attendance_date'], 'idx_attendances_status');
            $table->unique(
                ['schedule_id', 'student_id', 'attendance_date'],
                'unique_attendance_per_schedule'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
