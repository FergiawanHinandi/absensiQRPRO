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
        Schema::create('leaderboard_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('period_key'); // e.g., '2024-01' or '2024-SEM1'
            $table->string('category'); // 'student_attendance', 'student_streak', 'class_attendance'
            $table->integer('rank');
            $table->unsignedBigInteger('entity_id'); // User ID or Class ID
            $table->string('entity_type'); // 'student' or 'class'
            $table->string('entity_name'); // Snapshot name in case of deletion
            $table->decimal('score', 8, 2); // The value sorted by
            $table->json('metadata')->nullable(); // Extra details
            $table->timestamps();

            // Indexes for fast retrieval
            $table->index(['school_id', 'period_key', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaderboard_history');
    }
};
