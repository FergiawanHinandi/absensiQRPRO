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
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('user_type')->nullable(); // 'student', 'teacher', 'admin', etc.
            $table->string('event_type'); // 'outside_schedule', 'outside_radius', 'unapproved_device', 'duplicate_attempt', etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_id')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('context')->nullable(); // Additional context data
            $table->text('message')->nullable(); // Human-readable description
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['school_id', 'event_type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'severity']);
            $table->index(['is_resolved', 'severity']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
