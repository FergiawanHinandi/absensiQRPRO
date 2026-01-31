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
        Schema::create('student_attendance_risk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->integer('risk_score');
            $table->string('risk_level'); // low, medium, high
            $table->json('factors_json')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            // Index for quick lookup of high risk students
            $table->index(['risk_level', 'calculated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_attendance_risk');
    }
};
