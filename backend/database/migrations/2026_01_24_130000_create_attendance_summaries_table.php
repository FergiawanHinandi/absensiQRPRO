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
        Schema::create('attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('set null'); // Snapshot of class at that time

            $table->integer('year');
            $table->integer('month'); // 1-12

            $table->integer('present')->default(0);
            $table->integer('late')->default(0);
            $table->integer('sick')->default(0);
            $table->integer('absent')->default(0); // Alpha/Unexcused
            $table->integer('permit')->default(0); // Izin

            $table->timestamps();

            // Composite unique index to prevent duplicates
            $table->unique(['student_id', 'year', 'month']);
            $table->index(['school_id', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_summaries');
    }
};
