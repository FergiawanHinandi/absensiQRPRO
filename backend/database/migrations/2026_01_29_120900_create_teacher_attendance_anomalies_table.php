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
        Schema::create('teacher_attendance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('teacher_attendance_id')->nullable()->constrained('teacher_attendances')->onDelete('cascade');
            $table->string('anomaly_type'); // 'new_device', 'outside_radius', 'mock_location', 'poor_accuracy', etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->json('details')->nullable(); // Detailed info about the anomaly
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('device_id')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            // Indexes for querying
            $table->index(['school_id', 'anomaly_type']);
            $table->index(['teacher_id', 'created_at']);
            $table->index(['is_reviewed', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_anomalies');
    }
};
