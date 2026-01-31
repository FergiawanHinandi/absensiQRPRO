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
        Schema::create('attendance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->enum('report_type', ['daily', 'weekly', 'monthly', 'semester', 'yearly']);
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('cascade');
            $table->foreignId('student_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->date('report_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('total_days');
            $table->integer('present_count')->default(0);
            $table->integer('late_count')->default(0);
            $table->integer('absent_count')->default(0);
            $table->integer('sick_count')->default(0);
            $table->integer('permit_count')->default(0);
            $table->decimal('attendance_rate', 5, 2)->default(0)->comment('Percentage');
            $table->json('report_data')->comment('Detail data');
            $table->string('file_url', 255)->nullable()->comment('PDF/Excel export');
            $table->foreignId('generated_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            // Indexes
            $table->index(['school_id', 'report_type', 'report_date'], 'idx_reports_school');
            $table->index(['class_id', 'period_start', 'period_end'], 'idx_reports_class');
            $table->index(['student_id', 'period_start', 'period_end'], 'idx_reports_student');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_reports');
    }
};
