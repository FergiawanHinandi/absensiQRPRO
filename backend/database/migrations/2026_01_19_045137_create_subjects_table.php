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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->string('code', 20)->comment('Kode mapel, e.g. MAT, IPA');
            $table->string('name', 255);
            $table->enum('school_level', ['SD', 'SMP', 'SMA', 'SMK']);
            $table->integer('grade_level')->nullable()->comment('Tingkat kelas 1-12');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes & Constraints
            $table->index('school_id', 'idx_subjects_school');
            $table->unique(['school_id', 'code', 'grade_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
