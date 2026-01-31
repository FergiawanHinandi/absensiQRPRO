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
        Schema::create('student_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->integer('points');
            $table->string('source'); // attendance_daily, weekly_bonus, monthly_bonus
            $table->string('reference_id')->nullable(); // e.g., attendance_id or null for general bonus
            $table->date('date');
            $table->text('description')->nullable();
            $table->timestamps();

            // Indexes for fast aggregation
            $table->index(['student_id', 'source']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_points');
    }
};
