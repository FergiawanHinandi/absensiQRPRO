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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('certificate_code')->unique(); // E.g. CERT-2024-SEM1-USERID
            $table->string('type')->default('gold_attendance'); // 'gold_attendance', 'perfect_month', etc.
            $table->string('file_path'); // Path to PDF
            $table->string('semester'); // 'Ganjil', 'Genap'
            $table->year('academic_year'); // 2024
            $table->decimal('attendance_rate', 5, 2);
            $table->json('metadata')->nullable(); // Store extra details
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
