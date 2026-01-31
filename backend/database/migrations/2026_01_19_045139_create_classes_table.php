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
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('cascade');
            $table->string('name', 100)->comment('e.g. VII-A, X IPA 1');
            $table->integer('grade_level')->comment('Tingkat kelas 1-12');
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('max_students')->default(40);
            $table->string('classroom', 100)->nullable()->comment('Nama ruang kelas');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes & Constraints
            $table->index(['school_id', 'academic_year_id'], 'idx_classes_school');
            $table->index('homeroom_teacher_id', 'idx_classes_homeroom');
            $table->unique(['school_id', 'academic_year_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
