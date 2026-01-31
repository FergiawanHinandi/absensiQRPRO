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
        Schema::create('security_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('date_range')->default('7d');
            $table->json('summary_data')->nullable();
            $table->enum('generation_type', ['manual', 'auto'])->default('manual');
            $table->timestamp('report_period_start')->nullable();
            $table->timestamp('report_period_end')->nullable();
            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['school_id', 'created_at']);
            $table->index(['teacher_id', 'created_at']);
            $table->index(['generated_by', 'created_at']);
            $table->index('risk_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_reports');
    }
};
