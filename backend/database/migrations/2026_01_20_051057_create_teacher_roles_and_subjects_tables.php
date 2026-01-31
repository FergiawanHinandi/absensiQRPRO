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
        // Tabel untuk konfigurasi peran guru (wali kelas)
        Schema::create('teacher_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_homeroom_teacher')->default(false);
            $table->foreignId('homeroom_class_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('cascade');
            $table->timestamps();

            // Satu guru hanya bisa jadi wali kelas untuk satu kelas per tahun ajaran
            $table->unique(['teacher_id', 'academic_year_id'], 'unique_teacher_homeroom_per_year');
        });

        // Tabel untuk mapping guru - mata pelajaran - kelas
        Schema::create('teacher_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('cascade');
            $table->timestamps();

            // Satu guru tidak bisa mengajar mapel yang sama di kelas yang sama (duplikasi)
            $table->unique(
                ['teacher_id', 'subject_id', 'class_id', 'academic_year_id'],
                'unique_teacher_subject_class'
            );

            // Index untuk query performa
            $table->index(['teacher_id', 'academic_year_id'], 'idx_teacher_year');
            $table->index(['class_id', 'subject_id'], 'idx_class_subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_subjects');
        Schema::dropIfExists('teacher_roles');
    }
};
